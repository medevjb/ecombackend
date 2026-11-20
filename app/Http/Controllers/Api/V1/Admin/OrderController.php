<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderRefundResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Orders\OrderCancellation;
use App\Services\Orders\OrderDetails;
use App\Services\Orders\OrderTransitions;
use App\Services\Payments\OrderRefunds;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'number' => ['sometimes', 'required', 'string', 'max:24'],
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Order::query()->with('payment')->withExists('pendingCancellationRefunds as cancellation_refund_pending')->latest('id');
        foreach (['number', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $orders = $query->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, OrderResource::collection($orders), 'Orders retrieved.', meta: [
            'pagination' => [
                'current_page' => $orders->currentPage(), 'per_page' => $orders->perPage(),
                'total' => $orders->total(), 'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $order, OrderDetails $details): JsonResponse
    {
        return $this->apiSuccess($request, new OrderResource($details->hydrate($this->find($order)->loadExists('pendingCancellationRefunds as cancellation_refund_pending'))), 'Order retrieved.')
            ->header('Cache-Control', 'no-store');
    }

    public function transition(Request $request, string $order, OrderTransitions $orders, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['processing', 'shipped', 'delivered'])],
            'shipping_carrier' => ['exclude_unless:status,shipped', 'required', 'string', 'max:80'],
            'tracking_number' => ['exclude_unless:status,shipped', 'required', 'string', 'max:100'],
        ]);
        $changed = $orders->fulfill($order, OrderStatus::from($data['status']), $catalog->actor($request), Arr::only($data, ['shipping_carrier', 'tracking_number']));

        return $this->apiSuccess($request, new OrderResource($changed->loadMissing('payment')), 'Order status updated.');
    }

    public function cancel(Request $request, string $order, OrderCancellation $orders, OrderRefunds $refunds, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = $this->find($order);
        $actor = $catalog->actor($request);

        if ($record->payment->captured_amount === 0) {
            $cancelled = $orders->unpaid($record, 'staff', $actor->id, $data['reason']);

            return $this->apiSuccess($request, new OrderResource($cancelled), 'Order cancelled.');
        }

        Gate::forUser($actor)->authorize('orders.refund');
        $refund = $refunds->requestCancellation($record, $actor, $data['reason'], $this->key($request));

        return $this->apiSuccess($request, new OrderRefundResource($refund), 'Cancellation refund requested.', 202);
    }

    public function refund(Request $request, string $order, OrderRefunds $refunds, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $refund = $refunds->requestManual($this->find($order), $catalog->actor($request), (int) $data['amount'], $data['reason'], $this->key($request));

        return $this->apiSuccess($request, new OrderRefundResource($refund), 'Refund requested.', 202);
    }

    public function refunds(Request $request, string $order): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $items = OrderRefund::query()->where('order_id', $this->find($order)->id)->latest('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, OrderRefundResource::collection($items), 'Refunds retrieved.', meta: [
            'pagination' => [
                'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'last_page' => $items->lastPage(),
            ],
        ]);
    }

    private function key(Request $request): string
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        return $request->validate(['idempotency_key' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/']])['idempotency_key'];
    }

    private function find(string $id): Order
    {
        return Order::query()->with('payment')->where('public_id', $id)->firstOrFail();
    }
}
