<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AdminCustomerResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Catalog\CatalogMaintenance;
use App\Traits\ApiResponse;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CatalogMaintenance $pagination): JsonResponse
    {
        $data = $request->validate($this->pageRules() + [
            'search' => ['sometimes', 'required', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $query = User::query()->customers();
        if (isset($data['search'])) {
            $prefix = strtr($data['search'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn ($query) => $query
                ->whereRaw("email LIKE ? ESCAPE '!'", [$prefix])
                ->orWhereRaw("name LIKE ? ESCAPE '!'", [$prefix]));
        }
        if (isset($data['active'])) {
            $request->boolean('active') ? $query->whereNull('suspended_at') : $query->whereNotNull('suspended_at');
        }
        $customers = $query->latest('id')->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, AdminCustomerResource::collection($customers), 'Customers retrieved.', meta: $pagination->meta($customers))
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $customer): JsonResponse
    {
        $user = User::query()->customers()->where('public_id', $customer)->firstOrFail();

        return $this->apiSuccess($request, new AdminCustomerResource($user), 'Customer retrieved.')->header('Cache-Control', 'no-store');
    }

    public function orders(Request $request, string $customer, CatalogMaintenance $pagination): JsonResponse
    {
        $request->validate($this->pageRules());
        $user = User::query()->customers()->where('public_id', $customer)->firstOrFail();
        $orders = Order::query()->where('user_id', $user->id)->with('payment')->latest('created_at')->latest('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, OrderResource::collection($orders), 'Customer orders retrieved.', meta: $pagination->meta($orders))
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, string $customer): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:255']]);
        $active = $request->boolean('active');
        $user = DB::transaction(function () use ($customer, $data, $active): User {
            $user = User::query()->customers()->where('public_id', $customer)->lockForUpdate()->firstOrFail();
            if ($user->isSuspended() === $active) {
                if ($active) {
                    $user->unsuspend();
                } else {
                    $user->suspend($data['reason']);
                }
                TyroAudit::log('customer.status.updated', $user, ['active' => ! $active], ['active' => $active], ['reason' => $data['reason']]);
            }

            return $user;
        }, 5);

        return $this->apiSuccess($request, new AdminCustomerResource($user), 'Customer status updated.')->header('Cache-Control', 'no-store');
    }

    /** @return array<string, list<string>> */
    private function pageRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
