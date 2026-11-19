<?php

namespace App\Services\Promotions;

use App\Enums\CatalogStatus;
use App\Enums\CouponType;
use App\Enums\CouponUsageStatus;
use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\StaffUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CouponService
{
    /** @param array<string, mixed> $data */
    public function save(?string $id, array $data, StaffUser $actor): Coupon
    {
        try {
            return DB::transaction(function () use ($id, $data, $actor): Coupon {
                $coupon = $id === null ? new Coupon : Coupon::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
                $type = isset($data['type']) ? CouponType::from($data['type']) : $coupon->type;

                if ($id !== null && $coupon->type !== $type && ! isset($data['value'])) {
                    throw ValidationException::withMessages(['value' => ['Provide a value when changing the coupon type.']]);
                }
                if (isset($data['currency']) && $type === CouponType::Percentage) {
                    throw ValidationException::withMessages(['currency' => ['Currency applies only to a fixed coupon.']]);
                }

                $starts = array_key_exists('starts_at', $data) ? $this->date($data['starts_at']) : $coupon->starts_at;
                $ends = array_key_exists('ends_at', $data) ? $this->date($data['ends_at']) : $coupon->ends_at;
                if ($starts !== null && $ends !== null && $ends->lte($starts)) {
                    throw ValidationException::withMessages(['ends_at' => ['The end time must follow the start time.']]);
                }

                $coupon->fill(Arr::only($data, ['code', 'minimum_amount', 'global_limit', 'per_customer_limit']));
                $coupon->forceFill([
                    'type' => $type,
                    'value' => isset($data['value']) ? $this->storedValue($type, (string) $data['value']) : $coupon->value,
                    'currency' => $type === CouponType::Fixed ? 'USD' : null,
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'updated_by' => $actor->id,
                ]);
                if ($id === null) {
                    $coupon->forceFill(['created_by' => $actor->id]);
                }
                $changes = array_keys($coupon->getDirty());
                $coupon->save();
                $this->syncScopes($coupon, $data);
                $this->audit($coupon, $id === null ? 'coupon.created' : 'coupon.updated', [
                    'changed_fields' => array_values(array_unique([...$changes, ...array_keys(Arr::only($data, ['product_ids', 'category_ids']))])),
                ]);

                return $coupon->load(['categories', 'products']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['The coupon code has already been taken.']]);
        }
    }

    /** @param 'activate'|'deactivate'|'delete'|'restore' $action */
    public function mutate(string $id, string $action, StaffUser $actor): Coupon
    {
        return DB::transaction(function () use ($id, $action, $actor): Coupon {
            $query = Coupon::query()->where('public_id', $id)->lockForUpdate();
            $coupon = ($action === 'restore' ? $query->onlyTrashed() : $query)->firstOrFail();
            $coupon->forceFill(['updated_by' => $actor->id]);

            match ($action) {
                'activate' => $coupon->forceFill(['status' => CatalogStatus::Active])->save(),
                'deactivate' => $coupon->forceFill(['status' => CatalogStatus::Inactive])->save(),
                'delete' => $coupon->save() && $coupon->delete(),
                'restore' => $coupon->forceFill(['status' => CatalogStatus::Inactive])->restore(),
            };
            $this->audit($coupon, 'coupon.'.$action, ['status' => $coupon->status->value]);

            return $coupon->load(['categories', 'products']);
        }, 3);
    }

    /** @param array<string, mixed> $quote */
    public function estimate(Coupon $coupon, Cart $cart, array $quote, bool $locked = false): int
    {
        $this->requireActive($coupon);
        $this->requireCapacity($coupon, $cart->user_id, $locked);
        $subtotal = (int) $quote['subtotal_amount'];

        if ($subtotal < $coupon->minimum_amount) {
            throw new ApiProblem('COUPON_MINIMUM_SPEND', 'The cart does not meet the coupon minimum spend.', 422);
        }

        $eligible = $this->eligibleSubtotal($coupon, $quote);
        if ($eligible <= 0) {
            throw new ApiProblem('COUPON_INELIGIBLE', 'This coupon does not apply to any payable cart items.', 422);
        }

        if ($coupon->type === CouponType::Fixed) {
            return min($eligible, $coupon->value);
        }

        return intdiv($eligible, 10_000) * $coupon->value
            + intdiv(($eligible % 10_000) * $coupon->value, 10_000);
    }

    /** @param array<string, mixed> $quote */
    public function reserve(Coupon $coupon, Cart $cart, array $quote, string $orderReference, CarbonInterface $expiresAt): CouponRedemption
    {
        if ($cart->user_id === null || ! Str::isUlid($orderReference)) {
            throw new ApiProblem('COUPON_CUSTOMER_REQUIRED', 'Coupon redemption requires a customer and an order.');
        }

        $order = strtolower($orderReference);
        $fingerprint = hash('sha256', json_encode([
            $coupon->id, $cart->user_id, $quote['subtotal_amount'], $quote['items'], $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($coupon, $cart, $quote, $order, $expiresAt, $fingerprint): CouponRedemption {
                $locked = Coupon::query()->withTrashed()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                $previous = CouponRedemption::query()->where('order_reference', $order)->lockForUpdate()->first();
                if ($previous !== null) {
                    return $this->checkClaim($previous, $fingerprint);
                }
                if ($expiresAt->lte(now())) {
                    throw new ApiProblem('COUPON_EXPIRED', 'The coupon hold requires a future expiry.', 422);
                }
                $discount = $this->estimate($locked, $cart, $quote, locked: true);
                if ($discount === 0) {
                    throw new ApiProblem('COUPON_INELIGIBLE', 'The coupon does not produce a payable discount.', 422);
                }

                $claim = new CouponRedemption;
                $claim->forceFill([
                    'coupon_id' => $locked->id,
                    'user_id' => $cart->user_id,
                    'order_reference' => $order,
                    'order_id' => Order::query()->where('public_id', $order)->value('id'),
                    'fingerprint' => $fingerprint,
                    'discount_amount' => $discount,
                    'status' => CouponUsageStatus::Reserved,
                    'expires_at' => $expiresAt,
                ])->save();

                return $claim;
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            $previous = CouponRedemption::query()->where('order_reference', $order)->first();

            return $previous === null ? throw $exception : $this->checkClaim($previous, $fingerprint);
        }
    }

    public function redeem(string $orderReference): ?CouponRedemption
    {
        return $this->transition($orderReference, redeem: true);
    }

    public function release(string $orderReference): ?CouponRedemption
    {
        return $this->transition($orderReference, redeem: false);
    }

    private function transition(string $id, bool $redeem): ?CouponRedemption
    {
        $order = strtolower($id);
        $candidate = CouponRedemption::query()->where('order_reference', $order)->first();
        if ($candidate === null) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $redeem): CouponRedemption {
            $coupon = Coupon::query()->withTrashed()->whereKey($candidate->coupon_id)->lockForUpdate()->firstOrFail();
            $claim = CouponRedemption::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($claim->status === CouponUsageStatus::Redeemed || (! $redeem && $claim->status === CouponUsageStatus::Released)) {
                return $claim;
            }
            if ($claim->status === CouponUsageStatus::Released) {
                throw new ApiProblem('COUPON_RELEASED', 'The coupon hold has been released.');
            }
            if ($redeem) {
                $this->requireActive($coupon);
                $this->requireCapacity($coupon, $claim->user_id, true, $claim->id);
                $claim->forceFill(['status' => CouponUsageStatus::Redeemed, 'redeemed_at' => now()])->save();
            } else {
                $claim->forceFill(['status' => CouponUsageStatus::Released])->save();
            }

            return $claim;
        }, 5);
    }

    private function requireActive(Coupon $coupon): void
    {
        if ($coupon->trashed() || $coupon->status !== CatalogStatus::Active || $coupon->starts_at?->gt(now())) {
            throw new ApiProblem('COUPON_UNAVAILABLE', 'The coupon is unavailable.', 422);
        }
        if ($coupon->ends_at?->lte(now())) {
            throw new ApiProblem('COUPON_EXPIRED', 'The coupon has expired.', 422);
        }
    }

    private function requireCapacity(Coupon $coupon, ?int $customer, bool $locked, ?int $ignore = null): void
    {
        $claims = CouponRedemption::query()->where('coupon_id', $coupon->id)
            ->where(fn (Builder $used) => $used->where('status', CouponUsageStatus::Redeemed)
                ->orWhere(fn (Builder $held) => $held->where('status', CouponUsageStatus::Reserved)->where('expires_at', '>', now())));
        if ($ignore !== null) {
            $claims->whereKeyNot($ignore);
        }
        if ($locked) {
            $claims->lockForUpdate();
        }
        if ($coupon->global_limit !== null && (clone $claims)->count() >= $coupon->global_limit) {
            throw new ApiProblem('COUPON_EXHAUSTED', 'The coupon has reached its usage limit.', 422);
        }
        if ($coupon->per_customer_limit !== null && $customer !== null
            && $claims->where('user_id', $customer)->count() >= $coupon->per_customer_limit) {
            throw new ApiProblem('COUPON_CUSTOMER_LIMIT', 'This customer has reached the coupon usage limit.', 422);
        }
    }

    /** @param array<string, mixed> $quote */
    private function eligibleSubtotal(Coupon $coupon, array $quote): int
    {
        $coupon->loadMissing(['products', 'categories']);
        $productIds = $coupon->products->modelKeys();
        $categoryIds = $coupon->categories->modelKeys();
        if ($productIds === [] && $categoryIds === []) {
            return (int) $quote['subtotal_amount'];
        }

        $lines = array_filter($quote['items'], fn (array $item): bool => $item['line_amount'] !== null && $item['product_id'] !== null);
        $eligible = Product::query()->whereIn('public_id', array_column($lines, 'product_id'))
            ->where(fn (Builder $product) => $product->whereKey($productIds)->orWhereHas('categories', fn (Builder $category) => $category->whereKey($categoryIds)))
            ->pluck('public_id')->all();

        $eligibleLines = array_filter($lines, fn (array $item): bool => in_array($item['product_id'], $eligible, true));

        return (int) array_sum(array_column($eligibleLines, 'line_amount'));
    }

    private function storedValue(CouponType $type, string $value): int
    {
        if ($type === CouponType::Fixed && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }
        if ($type === CouponType::Percentage && preg_match('/\A(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)\z/', $value) === 1) {
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $points = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            if ($points > 0) {
                return $points;
            }
        }

        throw ValidationException::withMessages(['value' => ['Use a positive integer amount for fixed coupons or a percentage above 0 and up to 100.']]);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    private function checkClaim(CouponRedemption $claim, string $fingerprint): CouponRedemption
    {
        if ($claim->fingerprint !== $fingerprint) {
            throw new ApiProblem('IDEMPOTENCY_CONFLICT', 'This order already has a different coupon claim.');
        }

        return $claim;
    }

    /** @param array<string, mixed> $data */
    private function syncScopes(Coupon $coupon, array $data): void
    {
        if (isset($data['product_ids'])) {
            $ids = Product::query()->whereIn('public_id', $data['product_ids'])->pluck('id');
            if ($ids->count() !== count($data['product_ids'])) {
                throw ValidationException::withMessages(['product_ids' => ['At least one product is unavailable.']]);
            }
            $coupon->products()->sync($ids);
        }
        if (isset($data['category_ids'])) {
            $ids = Category::query()->whereIn('public_id', $data['category_ids'])->pluck('id');
            if ($ids->count() !== count($data['category_ids'])) {
                throw ValidationException::withMessages(['category_ids' => ['At least one category is unavailable.']]);
            }
            $coupon->categories()->sync($ids);
        }
    }

    /** @param array<string, mixed> $data */
    private function audit(Coupon $coupon, string $event, array $data): void
    {
        TyroAudit::log($event, $coupon, null, $data, ['request_id' => request()->attributes->get('api_request_id')]);
    }
}
