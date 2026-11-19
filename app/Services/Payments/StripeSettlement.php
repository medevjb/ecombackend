<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiProblem;
use App\Jobs\ReconcileOrderPayment;
use App\Jobs\SendPaymentExceptionNotice;
use App\Models\Cart;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Checkout\CheckoutAttempts;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderTransitions;
use App\Services\Promotions\CouponService;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;

class StripeSettlement
{
    public function __construct(
        private readonly StripePayments $stripe,
        private readonly CheckoutAttempts $checkout,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly OrderTransitions $orders,
    ) {}

    public function handle(int $eventId): void
    {
        $event = WebhookEvent::query()->whereKey($eventId)->firstOrFail();
        if ($event->processed_at !== null) {
            return;
        }
        if (str_starts_with($event->type, 'refund.')) {
            app(OrderRefunds::class)->handleEvent($event);

            return;
        }

        $intent = $this->stripe->getIntent($event->object_id);
        $attemptId = $intent->metadata['checkout_id'] ?? null;

        DB::transaction(function () use ($eventId, $intent, $attemptId): void {
            $locked = WebhookEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($locked->processed_at !== null) {
                return;
            }
            $attempt = is_string($attemptId) ? CheckoutAttempt::query()->where('public_id', $attemptId)->first() : null;
            if ($attempt !== null) {
                $order = $attempt->order()->lockForUpdate()->firstOrFail();
                $payment = $order->payment()->lockForUpdate()->firstOrFail();
                $outcome = $this->apply($locked, $intent, $attempt, $order, $payment);

                DB::table('payment_events')->insert([
                    'payment_id' => $payment->id, 'webhook_event_id' => $locked->id,
                    'provider_status' => $intent->status, 'outcome' => $outcome, 'created_at' => now(),
                ]);
            }
            $locked->forceFill(['processed_at' => now()])->save();
        }, 5);
    }

    private function apply(WebhookEvent $event, PaymentIntent $intent, CheckoutAttempt $attempt, Order $order, Payment $payment): string
    {
        try {
            $this->checkout->requireMatchingIntent($intent, $attempt, $payment);
        } catch (ApiProblem) {
            if ($payment->status === PaymentStatus::Pending) {
                $this->flagException($payment, 'PAYMENT_INTENT_MISMATCH');
            }

            return 'PAYMENT_INTENT_MISMATCH';
        }

        if ($payment->status === PaymentStatus::Exception && $payment->getAttribute('exception_code') === 'PAYMENT_INTENT_MISMATCH') {
            return 'manual_review';
        }
        if (in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
            return 'already_settled';
        }

        if ($payment->provider_intent_id === null) {
            $payment->forceFill(['provider_intent_id' => $intent->id])->save();
        }
        if ($intent->status === PaymentIntent::STATUS_SUCCEEDED) {
            if ($intent->amount_received !== $payment->expected_amount) {
                $this->flagException($payment, 'PAYMENT_AMOUNT_MISMATCH');

                return 'PAYMENT_AMOUNT_MISMATCH';
            }

            return $this->settle($attempt, $order, $payment, $intent);
        }

        if ($order->status === OrderStatus::Pending && ($intent->status === PaymentIntent::STATUS_CANCELED
            || ($event->type === 'payment_intent.payment_failed' && $intent->status === PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD))) {
            $this->inventory->release($attempt->public_id);
            $this->coupons->release($order->public_id);
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();
            $this->orders->record($order, OrderStatus::Cancelled, 'system', reason: 'Payment did not complete.');

            return 'payment_failed';
        }

        return 'no_change';
    }

    private function settle(CheckoutAttempt $attempt, Order $order, Payment $payment, PaymentIntent $intent): string
    {
        if ($payment->status === PaymentStatus::Exception) {
            return 'reconciliation_pending';
        }
        if ($order->status !== OrderStatus::Pending) {
            return $this->reconcile($payment, $intent, 'ORDER_NOT_PAYABLE');
        }

        $cart = Cart::query()->whereKey($attempt->cart->id)->lockForUpdate()->firstOrFail();
        try {
            DB::transaction(function () use ($attempt, $order, $intent): void {
                $this->inventory->commit($attempt->public_id, $intent->id);
                $claim = $this->coupons->redeem($order->public_id);
                if ($order->discount_amount > 0 && ($claim === null || $claim->discount_amount !== $order->discount_amount)) {
                    throw new ApiProblem('COUPON_REDEMPTION_MISMATCH', 'The coupon claim does not match the paid order.');
                }
            });
        } catch (ApiProblem $problem) {
            $this->inventory->release($attempt->public_id);
            $this->coupons->release($order->public_id);

            return $this->reconcile($payment, $intent, $problem->errorCode);
        }

        $payment->forceFill(['status' => PaymentStatus::Succeeded, 'captured_amount' => $intent->amount_received, 'captured_at' => now()])->save();
        $order->forceFill(['paid_at' => now()]);
        $this->orders->record($order, OrderStatus::Paid, 'system', reason: 'Verified Stripe payment.');
        $this->countSales($order);

        if ($this->checkout->cartFingerprint($cart) === $attempt->cart_fingerprint) {
            $cart->items()->delete();
            $cart->forceFill(['coupon_id' => null])->save();
        }

        return 'paid';
    }

    private function reconcile(Payment $payment, PaymentIntent $intent, string $code): string
    {
        $this->flagException($payment, $code, $intent->amount_received);
        ReconcileOrderPayment::dispatch($payment->order->id)->afterCommit();

        return $code;
    }

    private function flagException(Payment $payment, string $code, int $captured = 0): void
    {
        $payment->forceFill(['status' => PaymentStatus::Exception, 'captured_amount' => $captured, 'captured_at' => $captured > 0 ? now() : null, 'exception_code' => $code])->save();
        SendPaymentExceptionNotice::dispatch($payment->id)->afterCommit();
    }

    private function countSales(Order $order): void
    {
        $sales = DB::table('order_items')->where('order_id', $order->id)->select('product_id')
            ->selectRaw('SUM(quantity) AS units')->groupBy('product_id')->orderBy('product_id')->get();

        foreach ($sales as $sale) {
            DB::table('product_metrics')->insertOrIgnore(['product_id' => $sale->product_id]);
            DB::table('product_metrics')->where('product_id', $sale->product_id)->increment('paid_quantity', (int) $sale->units);
        }
    }
}
