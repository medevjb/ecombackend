<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\AddressBook;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryService;
use App\Services\Payments\StripePayments;
use App\Services\Promotions\CouponService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

class CheckoutAttempts
{
    public function __construct(
        private readonly AddressBook $addresses,
        private readonly CartService $carts,
        private readonly CheckoutPricing $pricing,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly StripePayments $stripe,
    ) {}

    /** @return array<string, mixed> */
    public function start(User $customer, string $addressId, string $key): array
    {
        $this->stripe->ensureConfigured();
        $attempt = $this->prepare($customer, strtolower($addressId), hash('sha256', $key));

        return $this->preparePayment($attempt);
    }

    /** @return array<string, mixed> */
    public function resume(User $customer, string $orderId): array
    {
        $order = Order::query()->where('user_id', $customer->id)->where('public_id', $orderId)->with('payment')->firstOrFail();
        if ($order->status !== OrderStatus::Pending || $order->payment->status !== PaymentStatus::Pending) {
            throw new ApiProblem('CHECKOUT_NOT_PENDING', 'This order no longer has a pending checkout.');
        }

        $this->stripe->ensureConfigured();

        return $this->preparePayment($order->checkoutAttempt->setRelation('order', $order));
    }

    /** @return array<string, mixed> */
    private function preparePayment(CheckoutAttempt $attempt): array
    {
        $order = $attempt->order;
        $payment = $order->payment;

        if ($order->status === OrderStatus::Cancelled) {
            throw new ApiProblem('CHECKOUT_CANCELLED', 'This checkout has been cancelled.');
        }
        if ($attempt->expires_at->lte(now()) && $payment->status === PaymentStatus::Pending) {
            throw new ApiProblem('CHECKOUT_EXPIRED', 'This checkout attempt has expired. Start a new attempt.');
        }

        $intent = $payment->provider_intent_id === null
            ? $this->stripe->createIntent($attempt->public_id, $payment->expected_amount, $payment->currency)
            : $this->stripe->getIntent($payment->provider_intent_id);
        $this->requireMatchingIntent($intent, $attempt, $payment);

        $cancelled = DB::transaction(function () use ($order, $payment, $intent): bool {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($current->provider_intent_id !== null && $current->provider_intent_id !== $intent->id) {
                throw new ApiProblem('PAYMENT_INTENT_CONFLICT', 'The payment reference did not match this checkout.');
            }

            if ($current->provider_intent_id === null) {
                $current->forceFill(['provider_intent_id' => $intent->id])->save();
            }

            return $lockedOrder->status === OrderStatus::Cancelled;
        }, 3);
        if ($cancelled) {
            throw new ApiProblem('CHECKOUT_CANCELLED', 'This checkout has been cancelled.');
        }

        return [
            'checkout_id' => $attempt->public_id,
            'order_id' => $order->public_id,
            'order_number' => $order->number,
            'status' => $order->status->value,
            'payment_status' => $payment->status->value,
            'client_secret' => $intent->client_secret,
            'expires_at' => $attempt->expires_at->toISOString(),
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
        ];
    }

    public function requireMatchingIntent(PaymentIntent $intent, CheckoutAttempt $attempt, Payment $payment): void
    {
        if ($intent->livemode !== false || $intent->amount !== $payment->expected_amount
            || strtoupper($intent->currency) !== $payment->currency
            || ($intent->metadata['checkout_id'] ?? null) !== $attempt->public_id
            || ($payment->provider_intent_id !== null && $payment->provider_intent_id !== $intent->id)) {
            throw new ApiProblem('PAYMENT_INTENT_MISMATCH', 'The payment does not match this checkout.');
        }
    }

    public function cartFingerprint(Cart $cart): string
    {
        $contents = $cart->items()->orderBy('id')->get(['id', 'product_variant_id', 'quantity'])
            ->map(fn (CartItem $item): array => [$item->getKey(), $item->product_variant_id, $item->quantity])->values()->all();

        return hash('sha256', json_encode([$cart->coupon_id, $contents], JSON_THROW_ON_ERROR));
    }

    private function prepare(User $customer, string $addressId, string $key): CheckoutAttempt
    {
        return DB::transaction(function () use ($customer, $addressId, $key): CheckoutAttempt {
            User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', $addressId);
            $existing = CheckoutAttempt::query()->where('user_id', $customer->id)->where('idempotency_hash', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ApiProblem('IDEMPOTENCY_CONFLICT', 'This key was already used for different checkout details.');
                }

                return $existing;
            }

            $address = $this->addresses->forCustomer($customer)->where('public_id', $addressId)->firstOrFail();
            $cart = Cart::query()->whereKey($this->carts->forCustomer($customer)->id)->lockForUpdate()->firstOrFail();
            $cartFingerprint = $this->cartFingerprint($cart);
            if (CheckoutAttempt::query()->where('cart_id', $cart->id)->where('cart_fingerprint', $cartFingerprint)
                ->where('expires_at', '>', now())->whereHas('order', fn ($order) => $order->where('status', OrderStatus::Pending))->exists()) {
                throw new ApiProblem('CHECKOUT_ALREADY_PENDING', 'Resume this cart’s existing checkout with its original key.');
            }

            $quote = $this->pricing->quote($cart, $address);
            if (! $quote['can_checkout']) {
                $code = $quote['conflicts'][0]['code'];

                throw new ApiProblem($code, 'Resolve the checkout preview conflicts before trying again.', $code === 'SHIPPING_NOT_CONFIGURED' ? 503 : 409);
            }
            if ($quote['total_amount'] < 50 || $quote['total_amount'] > 99_999_999) {
                throw new ApiProblem('STRIPE_AMOUNT_OUT_OF_RANGE', 'The total is outside the supported USD card payment range.', 422);
            }

            $variantQuantities = $cart->items()->pluck('quantity', 'product_variant_id')->map(fn (mixed $quantity): int => (int) $quantity)->all();
            $expires = now()->addMinutes(config()->integer('checkout.reservation_minutes'));
            $checkoutId = strtolower((string) Str::ulid());
            $hold = $this->inventory->reserve($checkoutId, $variantQuantities, $expires);

            $attempt = new CheckoutAttempt;
            $attempt->forceFill([
                'public_id' => $checkoutId, 'user_id' => $customer->id, 'cart_id' => $cart->id,
                'stock_reservation_id' => $hold->id, 'idempotency_hash' => $key,
                'request_fingerprint' => $fingerprint, 'cart_fingerprint' => $cartFingerprint, 'expires_at' => $expires,
            ])->save();

            $order = $attempt->order()->make();
            $order->forceFill(Arr::only($quote, ['currency', 'subtotal_amount', 'discount_amount', 'shipping_amount', 'tax_amount', 'total_amount']) + [
                'user_id' => $customer->id,
                'status' => OrderStatus::Pending,
                'number' => $this->nextNumber(),
                'coupon_code' => $quote['coupon']['code'] ?? null,
            ])->save();

            if ($cart->coupon !== null) {
                $claim = $this->coupons->reserve($cart->coupon, $cart, $quote, $order->public_id, $expires);
                if ($claim->discount_amount !== $order->discount_amount) {
                    throw new ApiProblem('COUPON_CHANGED', 'Coupon rules changed. Request a new checkout preview.');
                }
            }

            $this->storeSnapshots($order, $cart, $quote);
            $order->payment()->make()->forceFill([
                'status' => PaymentStatus::Pending,
                'expected_amount' => $order->total_amount,
                'captured_amount' => 0,
                'currency' => $order->currency,
            ])->save();

            return $attempt;
        }, 5);
    }

    /** @param array<string, mixed> $quote */
    private function storeSnapshots(Order $order, Cart $cart, array $quote): void
    {
        DB::table('order_addresses')->insert(['order_id' => $order->id] + Arr::except($quote['address'], ['id']));
        $records = $cart->items()->with('variant.values.attribute')->get()->keyBy('public_id');
        $lines = [];

        foreach ($quote['items'] as $item) {
            $variant = $records[$item['id']]->variant;
            $lines[] = [
                'order_id' => $order->id,
                'product_id' => $variant->getAttribute('product_id'),
                'product_variant_id' => $variant->id,
                'product_public_id' => $item['product_id'],
                'variant_public_id' => $item['variant_id'],
                'product_name' => $item['name'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_amount' => $item['unit_amount'],
                'line_amount' => $item['line_amount'],
                'options' => json_encode($variant->values->map(fn ($value) => [
                    'attribute' => $value->attribute?->name, 'value' => $value->name,
                ])->values()->all(), JSON_THROW_ON_ERROR),
            ];
        }

        DB::table('order_items')->insert($lines);
    }

    private function nextNumber(): string
    {
        $year = now()->year;
        DB::table('order_sequences')->insertOrIgnore(['year' => $year, 'next_number' => 1]);
        $sequence = DB::table('order_sequences')->where('year', $year)->lockForUpdate();
        $next = $sequence->value('next_number');
        $sequence->increment('next_number');

        return sprintf('ORD-%04d-%06d', $year, $next);
    }
}
