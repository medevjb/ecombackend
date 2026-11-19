<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\ApiProblem;
use App\Jobs\SendOrderRefundNotice;
use App\Jobs\SubmitStripeRefund;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\StaffUser;
use App\Models\WebhookEvent;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderTransitions;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Support\Facades\DB;
use Stripe\Refund;

class OrderRefunds
{
    public function __construct(
        private readonly StripePayments $stripe,
        private readonly OrderTransitions $orders,
        private readonly InventoryService $inventory,
    ) {}

    public function requestManual(Order $order, StaffUser $actor, int $amount, string $reason, string $key): OrderRefund
    {
        return $this->request($order->id, $amount, $reason, $key, 'manual', $actor->id);
    }

    public function requestCancellation(Order $order, StaffUser $actor, string $reason, string $key): OrderRefund
    {
        return $this->request($order->id, null, $reason, $key, 'cancellation', $actor->id);
    }

    public function reconcile(int $orderId): void
    {
        $this->request($orderId, null, 'Verified payment could not be fulfilled.', 'automatic-reconciliation', 'reconciliation');
    }

    public function submit(int $id): void
    {
        $record = OrderRefund::query()->with('order.payment')->whereKey($id)->firstOrFail();
        if ($record->status !== RefundStatus::Pending) {
            return;
        }
        $intent = $record->order->payment->provider_intent_id;
        if ($intent === null) {
            throw new ApiProblem('PAYMENT_REFERENCE_MISSING', 'The payment reference is unavailable.');
        }

        $remote = $record->provider_refund_id === null
            ? $this->stripe->refund($intent, $record->public_id, $record->amount)
            : $this->stripe->getRefund($record->provider_refund_id);
        DB::transaction(fn () => $this->apply($record, $remote), 5);
    }

    public function handleEvent(WebhookEvent $event): void
    {
        $remote = $this->stripe->getRefund($event->object_id);
        $publicId = $remote->metadata['refund_id'] ?? null;

        DB::transaction(function () use ($event, $publicId, $remote): void {
            $receipt = WebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($receipt->processed_at !== null) {
                return;
            }
            $record = OrderRefund::query()->where('provider_refund_id', $remote->id)->first();
            if ($record === null && is_string($publicId)) {
                $record = OrderRefund::query()->where('public_id', $publicId)->first();
            }
            if ($record !== null) {
                $this->apply($record, $remote);
                DB::table('payment_events')->insert([
                    'payment_id' => $record->order->payment->id,
                    'webhook_event_id' => $receipt->id,
                    'provider_status' => $remote->status,
                    'outcome' => 'refund_updated',
                    'created_at' => now(),
                ]);
            }

            $receipt->forceFill(['processed_at' => now()])->save();
        }, 5);
    }

    private function request(int $orderId, ?int $amount, string $reason, string $key, string $purpose, ?int $actorId = null): OrderRefund
    {
        return DB::transaction(function () use ($orderId, $amount, $reason, $key, $purpose, $actorId): OrderRefund {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = $order->payment()->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $key);
            $prior = OrderRefund::query()->where('order_id', $order->id)->where('idempotency_hash', $hash)->first();
            if ($prior !== null) {
                if ($prior->purpose !== $purpose || $prior->reason !== $reason || ($amount !== null && $prior->amount !== $amount)) {
                    throw new ApiProblem('IDEMPOTENCY_CONFLICT', 'This key was already used for a different refund.');
                }
                if ($prior->status === RefundStatus::Pending) {
                    SubmitStripeRefund::dispatch($prior->id)->afterCommit();
                }

                return $prior;
            }
            $this->authorizePurpose($order, $payment, $purpose);
            if ($payment->provider_intent_id === null) {
                throw new ApiProblem('PAYMENT_REFERENCE_MISSING', 'The payment is not eligible for a refund.');
            }

            $records = OrderRefund::query()->where('order_id', $order->id);
            $amountAlreadyClaimed = (int) (clone $records)->whereIn('status', [RefundStatus::Pending, RefundStatus::Succeeded])->sum('amount');
            if ($purpose !== 'manual' && (clone $records)->where('status', RefundStatus::Pending)->exists()) {
                throw new ApiProblem('REFUND_PENDING', 'Resolve the pending refund before cancelling this order.');
            }
            $remaining = $payment->captured_amount - $amountAlreadyClaimed;
            $requested = $amount ?? $remaining;
            if ($requested < 1 || $requested > $remaining) {
                throw new ApiProblem('REFUND_AMOUNT_INVALID', 'The refund exceeds the remaining captured amount.', 422);
            }

            if ($payment->status === PaymentStatus::Exception && $requested !== $remaining) {
                throw new ApiProblem('REFUND_AMOUNT_INVALID', 'An exceptional payment requires a full remaining refund.', 422);
            }

            $refund = new OrderRefund;
            $refund->forceFill([
                'order_id' => $order->id, 'actor_id' => $actorId, 'purpose' => $purpose, 'status' => RefundStatus::Pending,
                'currency' => $payment->currency, 'amount' => $requested, 'reason' => $reason, 'idempotency_hash' => $hash,
            ])->save();
            TyroAudit::log('order.refund.requested', $order, null, [
                'refund_id' => $refund->public_id, 'amount' => $requested, 'currency' => $payment->currency,
            ], ['request_id' => request()->attributes->get('api_request_id')]);
            SubmitStripeRefund::dispatch($refund->id)->afterCommit();

            return $refund;
        }, 5);
    }

    private function authorizePurpose(Order $order, Payment $payment, string $purpose): void
    {
        if ($purpose === 'reconciliation') {
            if ($payment->status !== PaymentStatus::Exception) {
                throw new ApiProblem('REFUND_NOT_ALLOWED', 'The payment does not require a reconciliation refund.');
            }

            return;
        }

        if ($purpose === 'manual' && $payment->status === PaymentStatus::Exception && $payment->captured_amount > 0) {
            return;
        }
        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true)) {
            throw new ApiProblem('REFUND_NOT_ALLOWED', 'Only settled payments can be refunded.');
        }
        if ($purpose === 'cancellation' && ! in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true)) {
            throw new ApiProblem('ORDER_TRANSITION_INVALID', 'The order cannot be cancelled in its current state.');
        }
    }

    private function apply(OrderRefund $candidate, Refund $remote): void
    {
        $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
        $payment = $order->payment()->lockForUpdate()->firstOrFail();
        $record = OrderRefund::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

        if ($remote->amount !== $record->amount || strtoupper($remote->currency) !== $record->currency
            || $remote->payment_intent !== $payment->provider_intent_id
            || ($record->provider_refund_id !== null && $record->provider_refund_id !== $remote->id)) {
            throw new ApiProblem('REFUND_PROVIDER_MISMATCH', 'The provider refund does not match the recorded request.');
        }

        if ($record->status !== RefundStatus::Pending) {
            return;
        }
        $status = match ($remote->status) {
            'succeeded' => RefundStatus::Succeeded,
            'failed', 'canceled' => RefundStatus::Failed,
            default => RefundStatus::Pending,
        };
        $record->forceFill(['status' => $status, 'provider_refund_id' => $remote->id,
            'completed_at' => $status === RefundStatus::Succeeded ? now() : null,
        ])->save();

        if ($status === RefundStatus::Succeeded) {
            $this->applySuccessfulRefund($order, $payment, $record);
        }
        if ($status !== RefundStatus::Pending) {
            TyroAudit::log('order.refund.'.$status->value, $order, null, ['refund_id' => $record->public_id, 'amount' => $record->amount]);
            SendOrderRefundNotice::dispatch($record->id)->afterCommit();
        }
    }

    private function applySuccessfulRefund(Order $order, Payment $payment, OrderRefund $record): void
    {
        $refunded = (int) OrderRefund::query()->where('order_id', $order->id)
            ->where('status', RefundStatus::Succeeded)->lockForUpdate()->sum('amount');
        $full = $refunded === $payment->captured_amount;
        $firstFullRefund = $full && $payment->status !== PaymentStatus::Refunded;
        $reconciled = $payment->status === PaymentStatus::Exception && $order->paid_at === null;

        $payment->forceFill(['status' => $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded])->save();
        if ($firstFullRefund && $order->paid_at !== null) {
            $this->removeFromSales($order);
        }
        if ($full && $record->purpose === 'cancellation') {
            $this->inventory->restoreCommitted($order->checkoutAttempt->public_id, $record->public_id);
            $this->orders->record($order, OrderStatus::Cancelled, 'staff', $record->actor_id, 'Verified refund completed.');
        }
        if ($full && ($record->purpose === 'reconciliation' || $reconciled)) {
            $this->orders->record($order, OrderStatus::Cancelled, $record->actor_id === null ? 'system' : 'staff', $record->actor_id, 'Verified reconciliation refund completed.');
        }
    }

    private function removeFromSales(Order $order): void
    {
        $sales = DB::table('order_items')->where('order_id', $order->id)->select('product_id')
            ->selectRaw('SUM(quantity) AS units')->groupBy('product_id')->orderBy('product_id')->get();
        foreach ($sales as $sale) {
            DB::table('product_metrics')->where('product_id', $sale->product_id)
                ->where('paid_quantity', '>=', (int) $sale->units)->decrement('paid_quantity', (int) $sale->units);
        }
    }
}
