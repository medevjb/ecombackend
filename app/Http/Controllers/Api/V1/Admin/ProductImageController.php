<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UploadProductImageRequest;
use App\Http\Resources\Api\V1\ProductImageResource;
use App\Models\Product;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\ProductImages;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductImageController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $product): JsonResponse
    {
        $record = Product::query()->where('public_id', $product)->firstOrFail();
        $images = $record->images()->orderBy('sort_order')->orderBy('id')->get();

        return $this->apiSuccess($request, ProductImageResource::collection($images), 'Product images retrieved.');
    }

    public function store(UploadProductImageRequest $request, string $product, ProductImages $images, CatalogMaintenance $catalog): JsonResponse
    {
        $media = $images->upload($product, null, $request->validated('image'), $request->safe()->only(['alt_text', 'primary']), $catalog->actor($request));

        return $this->apiSuccess($request, new ProductImageResource($media), 'Product image uploaded.', 201);
    }

    public function replace(UploadProductImageRequest $request, string $product, string $image, ProductImages $images, CatalogMaintenance $catalog): JsonResponse
    {
        $media = $images->upload($product, $image, $request->validated('image'), $request->safe()->only(['alt_text', 'primary']), $catalog->actor($request));

        return $this->apiSuccess($request, new ProductImageResource($media), 'Product image replaced.');
    }

    public function update(Request $request, string $product, string $image, ProductImages $images, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary' => ['sometimes', 'required', 'boolean'],
        ]);
        $media = $images->update($product, $image, $data, $catalog->actor($request));

        return $this->apiSuccess($request, new ProductImageResource($media), 'Product image updated.');
    }

    public function destroy(Request $request, string $product, string $image, ProductImages $images, CatalogMaintenance $catalog): Response
    {
        $images->delete($product, $image, $catalog->actor($request));

        return response()->noContent();
    }

    public function reorder(Request $request, string $product, ProductImages $images, CatalogMaintenance $catalog): Response
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'list', 'max:1000'],
            'ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ]);
        $images->reorder($product, $data['ids'], $catalog->actor($request));

        return response()->noContent();
    }
}
