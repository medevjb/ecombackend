<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiProblem;
use App\Models\Order;
use App\Services\Inventory\InventoryService;
use App\Services\Payments\StripePayments;
use App\Services\Promotions\CouponService;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;

class OrderCancellation
{
    public function __construct(
        private readonly StripePayments $stripe,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly OrderTransitions $orders,
    ) {}

    public function unpaid(Order $order, string $actorType, int $actorId, string $reason): Order
    {
        if ($order->status === OrderStatus::Cancelled) {
            return $order;
        }
        if ($order->status !== OrderStatus::Pending || $order->payment->captured_amount > 0) {
            throw new ApiProblem('ORDER_TRANSITION_INVALID', 'Only unpaid pending orders can be cancelled directly.');
        }

        $providerId = $order->payment->provider_intent_id;
        if ($providerId !== null) {
            $intent = $this->stripe->getIntent($providerId);
            if (in_array($intent->status, [PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING], true)) {
                throw new ApiProblem('PAYMENT_IN_PROGRESS', 'Wait for payment review before cancelling this order.');
            }
            if ($intent->status !== PaymentIntent::STATUS_CANCELED
                && $this->stripe->cancelIntent($providerId)->status !== PaymentIntent::STATUS_CANCELED) {
                throw new ApiProblem('PAYMENT_IN_PROGRESS', 'The provider has not cancelled the payment.');
            }
        }

        return DB::transaction(function () use ($order, $providerId, $actorType, $actorId, $reason): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payment = $locked->payment()->lockForUpdate()->firstOrFail();
            if ($locked->status === OrderStatus::Cancelled) {
                return $locked->setRelation('payment', $payment);
            }
            if ($locked->status !== OrderStatus::Pending || $payment->captured_amount > 0
                || $payment->provider_intent_id !== $providerId) {
                throw new ApiProblem('PAYMENT_IN_PROGRESS', 'The payment state changed. Review the order before cancelling.');
            }

            $this->inventory->release($locked->checkoutAttempt->public_id);
            $this->coupons->release($locked->public_id);
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();
            $locked->setRelation('payment', $payment);

            return $this->orders->record($locked, OrderStatus::Cancelled, $actorType, $actorId, $reason);
        }, 5);
    }
}
