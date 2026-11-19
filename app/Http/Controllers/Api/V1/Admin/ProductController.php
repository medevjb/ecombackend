<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\ProductCatalog;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'sort_by' => ['sometimes', Rule::in(['name', 'regular_amount', 'created_at', 'published_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'trashed' => ['sometimes', 'boolean'],
        ]);
        $query = Product::query()->with(['brand', 'categories']);
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search'])) {
            $prefix = strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("name LIKE ? ESCAPE '!'", [$prefix]);
        }
        $direction = $filters['sort_direction'] ?? 'desc';
        $items = $query->orderBy($filters['sort_by'] ?? 'created_at', $direction)->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, ProductResource::collection($items), 'Products retrieved.', meta: [
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $record = Product::query()->with(['brand', 'categories'])->where('public_id', $product)->firstOrFail();

        return $this->item($request, $record, 'Product retrieved.');
    }

    public function store(SaveProductRequest $request, ProductCatalog $products, CatalogMaintenance $catalog): JsonResponse
    {
        return $this->item($request, $products->save(null, $request->validated(), $catalog->actor($request)), 'Product created.', 201);
    }

    public function update(SaveProductRequest $request, string $product, ProductCatalog $products, CatalogMaintenance $catalog): JsonResponse
    {
        return $this->item($request, $products->save($product, $request->validated(), $catalog->actor($request)), 'Product updated.');
    }

    public function status(Request $request, string $product, ProductCatalog $products, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(ProductStatus::class)]]);
        $record = $products->setStatus($product, ProductStatus::from($data['status']), $catalog->actor($request));

        return $this->item($request, $record, 'Product status updated.');
    }

    public function destroy(Request $request, string $product, ProductCatalog $products, CatalogMaintenance $catalog): Response
    {
        $products->archive($product, $catalog->actor($request));

        return response()->noContent();
    }

    public function restore(Request $request, string $product, ProductCatalog $products, CatalogMaintenance $catalog): JsonResponse
    {
        return $this->item($request, $products->restore($product, $catalog->actor($request)), 'Product restored as a draft.');
    }

    public function bulk(Request $request, ProductCatalog $products, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete', 'restore'])],
            'ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ]);
        $actor = $catalog->actor($request);
        $permission = in_array($data['action'], ['delete', 'restore'], true) ? 'products.delete' : 'products.update';
        $allowed = Gate::forUser($actor)->allows($permission);
        $success = [];
        $failures = [];
        foreach ($data['ids'] as $id) {
            if (! $allowed) {
                $failures[] = ['id' => $id, 'code' => 'FORBIDDEN'];

                continue;
            }

            try {
                match ($data['action']) {
                    'activate' => $products->setStatus($id, ProductStatus::Active, $actor),
                    'deactivate' => $products->setStatus($id, ProductStatus::Inactive, $actor),
                    'delete' => $products->archive($id, $actor),
                    'restore' => $products->restore($id, $actor),
                    default => throw ValidationException::withMessages(['action' => ['The selected bulk action is invalid.']]),
                };
                $success[] = $id;
            } catch (ModelNotFoundException) {
                $failures[] = ['id' => $id, 'code' => 'NOT_FOUND'];
            } catch (ValidationException $exception) {
                $failures[] = ['id' => $id, 'code' => 'VALIDATION_FAILED', 'fields' => $exception->errors()];
            }
        }

        return $this->apiSuccess($request, [
            'succeeded_ids' => $success,
            'failed' => $failures,
            'counts' => ['succeeded' => count($success), 'failed' => count($failures)],
        ], 'Product bulk action completed.');
    }

    private function item(Request $request, Product $record, string $message, int $status = 200): JsonResponse
    {
        return $this->apiSuccess($request, new ProductResource($record->loadMissing(['brand', 'categories'])), $message, $status);
    }
}
