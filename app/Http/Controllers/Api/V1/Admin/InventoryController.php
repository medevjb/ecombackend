<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InventoryMovementResource;
use App\Http\Resources\Api\V1\InventoryResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Inventory\InventoryService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate($this->paginationRules() + [
            'low_stock' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'required', 'string', 'max:100'],
        ]);
        $threshold = config()->integer('inventory.low_stock_threshold');
        $query = Inventory::query()->whereHas('variant')->with('variant.product');
        $lowStock = (clone $query)->whereRaw('on_hand - reserved <= ?', [$threshold])->count();

        if (array_key_exists('low_stock', $filters)) {
            $query->whereRaw($request->boolean('low_stock') ? 'on_hand - reserved <= ?' : 'on_hand - reserved > ?', [$threshold]);
        }
        if (isset($filters['search'])) {
            $prefix = strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereHas('variant', fn (Builder $variant) => $variant->whereRaw("sku LIKE ? ESCAPE '!'", [$prefix]));
        }
        $items = $query->orderBy('product_variant_id')->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, InventoryResource::collection($items), 'Inventory retrieved.', meta: [
            ...$this->meta($items),
            'low_stock_count' => $lowStock,
            'low_stock_threshold' => $threshold,
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate($this->paginationRules() + [
            'variant_id' => ['sometimes', 'required', 'ulid'],
            'type' => ['sometimes', Rule::in(['adjustment', 'payment', 'restoration'])],
        ]);
        $query = InventoryMovement::query()->with('inventory.variant');
        if (isset($filters['variant_id'])) {
            $query->whereHas('inventory.variant', fn (Builder $variant) => $variant->where('public_id', $filters['variant_id']));
        }
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, InventoryMovementResource::collection($items), 'Inventory movements retrieved.', meta: $this->meta($items));
    }

    public function adjust(Request $request, InventoryService $inventory, CatalogMaintenance $catalog): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate([
            'variant_id' => ['required', 'ulid'],
            'delta' => ['required', 'integer', 'between:-4294967295,4294967295', Rule::notIn([0])],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
        ]);
        $movement = $inventory->adjust($data['variant_id'], (int) $data['delta'], $data['reason'], $catalog->actor($request), $data['idempotency_key']);
        $status = $movement->wasRecentlyCreated ? 201 : 200;

        return $this->apiSuccess($request, new InventoryMovementResource($movement->load('inventory.variant')), 'Inventory adjustment recorded.', $status);
    }

    /** @return array<string, list<string>> */
    private function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /**
     * @template T of Inventory|InventoryMovement
     *
     * @param  LengthAwarePaginator<int, T>  $items
     * @return array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    private function meta(LengthAwarePaginator $items): array
    {
        return ['pagination' => [
            'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
            'total' => $items->total(), 'last_page' => $items->lastPage(),
        ]];
    }
}
