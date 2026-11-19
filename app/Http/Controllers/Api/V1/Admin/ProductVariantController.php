<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveProductVariantRequest;
use App\Http\Resources\Api\V1\ProductVariantResource;
use App\Models\Product;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\ProductVariants;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $product): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
        $record = Product::query()->where('public_id', $product)->firstOrFail();
        $items = $record->variants()->with('values')->orderByDesc('is_default')->orderBy('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, ProductVariantResource::collection($items), 'Product variants retrieved.', meta: [
            'pagination' => [
                'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(SaveProductVariantRequest $request, string $product, ProductVariants $variants, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $variants->save($product, null, $request->validated(), $catalog->actor($request));

        return $this->apiSuccess($request, new ProductVariantResource($record), 'Product variant created.', 201);
    }

    public function update(SaveProductVariantRequest $request, string $product, string $variant, ProductVariants $variants, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $variants->save($product, $variant, $request->validated(), $catalog->actor($request));

        return $this->apiSuccess($request, new ProductVariantResource($record), 'Product variant updated.');
    }

    public function status(Request $request, string $product, string $variant, ProductVariants $variants, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);
        $record = $variants->setStatus($product, $variant, CatalogStatus::from($data['status']), $catalog->actor($request));

        return $this->apiSuccess($request, new ProductVariantResource($record), 'Product variant status updated.');
    }

    public function destroy(Request $request, string $product, string $variant, ProductVariants $variants, CatalogMaintenance $catalog): Response
    {
        $variants->archive($product, $variant, $catalog->actor($request));

        return response()->noContent();
    }
}
