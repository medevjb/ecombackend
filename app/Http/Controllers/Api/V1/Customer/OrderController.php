<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderCancellation;
use App\Services\Orders\OrderDetails;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $orders = $this->owned($request)->with('payment')->latest('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, OrderResource::collection($orders), 'Orders retrieved.', meta: [
            'pagination' => [
                'current_page' => $orders->currentPage(), 'per_page' => $orders->perPage(),
                'total' => $orders->total(), 'last_page' => $orders->lastPage(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $order, OrderDetails $details): JsonResponse
    {
        $record = $this->owned($request)->where('public_id', $order)->firstOrFail();

        return $this->apiSuccess($request, new OrderResource($details->hydrate($record)), 'Order retrieved.')
            ->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request, string $order, OrderCancellation $orders): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'required', 'string', 'max:500']]);
        $record = $this->owned($request)->with('payment')->where('public_id', $order)->firstOrFail();
        $cancelled = $orders->unpaid($record, 'customer', $record->user_id, $data['reason'] ?? 'Customer cancelled before payment.');

        return $this->apiSuccess($request, new OrderResource($cancelled), 'Order cancelled.');
    }

    /** @return Builder<Order> */
    private function owned(Request $request): Builder
    {
        $customer = $request->user();
        abort_unless($customer instanceof User, 401);

        return Order::query()->where('user_id', $customer->id);
    }
}
