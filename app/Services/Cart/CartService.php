<?php

namespace App\Services\Cart;

use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Promotions\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CartService
{
    public const int MAX_ITEM_QUANTITY = 99;

    private const int MAX_LINES = 100;

    public function __construct(private readonly CartPricing $pricing) {}

    /** @return array{cart: Cart, guest_token: string|null} */
    public function resolve(?User $customer, ?string $token): array
    {
        if ($customer !== null) {
            return ['cart' => $this->forCustomer($customer), 'guest_token' => null];
        }
        if ($token !== null) {
            return ['cart' => $this->guest($token), 'guest_token' => null];
        }

        $token = bin2hex(random_bytes(32));
        $cart = new Cart;
        $cart->forceFill(['guest_token_hash' => hash('sha256', $token)])->save();

        return ['cart' => $cart, 'guest_token' => $token];
    }

    /** @return array{cart: Cart, guest_token: string|null} */
    public function resolveRequest(Request $request): array
    {
        $customer = null;
        if ($request->hasHeader('Authorization')) {
            $customer = Auth::guard('customer')->user();
            abort_unless($customer instanceof User, 401);
            Gate::forUser($customer)->authorize('customer-account');
        }

        return $this->resolve($customer, $request->header('X-Cart-Token'));
    }

    public function setCoupon(Cart $cart, ?Coupon $coupon, CouponService $coupons): Cart
    {
        return DB::transaction(function () use ($cart, $coupon, $coupons): Cart {
            $locked = $this->lock($cart);

            if ($coupon !== null) {
                $coupons->estimate($coupon, $locked, $this->pricing->quote($locked, includeCoupon: false));
            }

            $locked->coupon()->associate($coupon);
            $locked->save();

            return $locked;
        }, 3);
    }

    public function forCustomer(User $customer): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $customer->id]);
    }

    public function add(Cart $cart, string $variantId, int $quantity): void
    {
        DB::transaction(function () use ($cart, $variantId, $quantity): void {
            $locked = $this->lock($cart);
            $variant = $this->pricing->eligibleVariants()->where('public_id', $variantId)->first();
            if ($variant === null) {
                throw new ApiProblem('VARIANT_UNAVAILABLE', 'The selected variant is unavailable.', 404);
            }

            $item = $locked->items()->firstOrNew(['product_variant_id' => $variant->id]);
            $total = ($item->exists ? $item->quantity : 0) + $quantity;
            if ($total > self::MAX_ITEM_QUANTITY || $total > $this->pricing->available($variant)) {
                throw new ApiProblem('INSUFFICIENT_STOCK', 'The requested quantity exceeds available stock or the cart quantity limit.');
            }
            if (! $item->exists && $locked->items()->count() >= self::MAX_LINES) {
                throw new ApiProblem('CART_LIMIT', 'This cart has reached its item limit.');
            }

            $item->quantity = $total;
            $item->save();
        }, 3);
    }

    public function update(Cart $cart, string $itemId, int $quantity): void
    {
        DB::transaction(function () use ($cart, $itemId, $quantity): void {
            $locked = $this->lock($cart);
            $item = $locked->items()->where('public_id', $itemId)->firstOrFail();
            $variant = $this->pricing->eligibleVariants()->whereKey($item->product_variant_id)->first();
            if ($this->pricing->available($variant) < $quantity) {
                throw new ApiProblem('INSUFFICIENT_STOCK', 'The requested quantity is no longer available.');
            }

            $item->quantity = $quantity;
            $item->save();
        }, 3);
    }

    public function remove(Cart $cart, ?string $itemId): void
    {
        DB::transaction(function () use ($cart, $itemId): void {
            $items = $this->lock($cart)->items();

            if ($itemId === null) {
                $items->delete();
            } else {
                $items->where('public_id', $itemId)->firstOrFail()->delete();
            }
        }, 3);
    }

    /** @return array{cart: Cart, conflicts: list<array{variant_id: string, requested_quantity: int, quantity: int, code: string}>} */
    public function merge(User $customer, string $token): array
    {
        return DB::transaction(function () use ($customer, $token): array {
            User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $cart = $this->lock($this->forCustomer($customer));
            $guest = $this->guest($token, lock: true);
            $owned = $cart->items()->with('variant')->orderBy('id')->get()->keyBy('product_variant_id');
            $imported = $guest->items()->with('variant')->orderBy('id')->get()->keyBy('product_variant_id');
            $ids = array_values(array_unique([...$owned->keys()->all(), ...$imported->keys()->all()]));
            $offers = $this->pricing->offers($ids);
            $conflicts = [];
            $written = 0;

            foreach ($ids as $id) {
                $item = $owned->get($id);
                $line = $imported->get($id);
                $wanted = ($item->quantity ?? 0) + ($line->quantity ?? 0);
                $limitReached = $written >= self::MAX_LINES;
                $allowed = $limitReached ? 0 : min($wanted, self::MAX_ITEM_QUANTITY, $this->pricing->available($offers->get($id)));

                if ($allowed === 0) {
                    $item?->delete();
                } else {
                    $target = $item ?? $cart->items()->make(['product_variant_id' => $id]);
                    $target->quantity = $allowed;
                    $target->save();
                    $written++;
                }
                if ($allowed !== $wanted) {
                    /** @var CartItem $source */
                    $source = $item ?? $line;
                    $conflicts[] = [
                        'variant_id' => $source->variant->public_id,
                        'requested_quantity' => $wanted,
                        'quantity' => $allowed,
                        'code' => $limitReached ? 'CART_LIMIT' : 'INSUFFICIENT_STOCK',
                    ];
                }
            }

            if ($cart->coupon_id === null && $guest->coupon_id !== null) {
                $cart->forceFill(['coupon_id' => $guest->coupon_id])->save();
            }

            $guest->delete();

            return ['cart' => $cart, 'conflicts' => $conflicts];
        }, 3);
    }

    private function guest(string $token, bool $lock = false): Cart
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new ApiProblem('CART_NOT_FOUND', 'The guest cart is unavailable.', 404);
        }
        $query = Cart::query()->where('guest_token_hash', hash('sha256', $token));
        $cart = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($cart === null) {
            throw new ApiProblem('CART_NOT_FOUND', 'The guest cart is unavailable.', 404);
        }

        return $cart;
    }

    private function lock(Cart $cart): Cart
    {
        return Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
    }
}
