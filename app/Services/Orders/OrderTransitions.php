<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiProblem;
use App\Jobs\SendOrderStatusNotice;
use App\Models\Order;
use App\Models\StaffUser;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Support\Facades\DB;

class OrderTransitions
{
    /** @param array{shipping_carrier?: string, tracking_number?: string} $delivery */
    public function fulfill(string $id, OrderStatus $next, StaffUser $staff, array $delivery): Order
    {
        return DB::transaction(function () use ($id, $next, $staff, $delivery): Order {
            $order = Order::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            if ($order->payment->status === PaymentStatus::Refunded) {
                throw new ApiProblem('ORDER_TRANSITION_INVALID', 'A fully refunded order cannot advance fulfilment.');
            }

            $allowed = match ($order->status) {
                OrderStatus::Paid => OrderStatus::Processing,
                OrderStatus::Processing => OrderStatus::Shipped,
                OrderStatus::Shipped => OrderStatus::Delivered,
                default => null,
            };

            if ($order->pendingCancellationRefunds()->exists()) {
                throw new ApiProblem('REFUND_PENDING', 'Fulfilment is paused while cancellation is pending.');
            }
            if ($next !== $allowed) {
                throw new ApiProblem('ORDER_TRANSITION_INVALID', 'This order cannot make the requested transition.');
            }
            if ($next === OrderStatus::Shipped) {
                $order->forceFill($delivery);
            }

            return $this->record($order, $next, 'staff', $staff->id);
        }, 5);
    }

    public function record(Order $order, OrderStatus $next, string $actorType, ?int $actorId = null, ?string $reason = null): Order
    {
        $current = $order->status;
        if ($current === $next) {
            return $order;
        }

        $order->forceFill(['status' => $next])->save();
        $historyId = DB::table('order_status_histories')->insertGetId([
            'order_id' => $order->id, 'actor_type' => $actorType, 'actor_user_id' => $actorId,
            'from_status' => $current->value, 'to_status' => $next->value,
            'reason' => $reason, 'request_id' => request()->attributes->get('api_request_id'), 'created_at' => now(),
        ]);
        TyroAudit::log('order.status.updated', $order, ['status' => $current->value], ['status' => $next->value], [
            'request_id' => request()->attributes->get('api_request_id'),
            'actor_type' => $actorType,
        ]);
        SendOrderStatusNotice::dispatch($historyId)->afterCommit();

        return $order;
    }
}
