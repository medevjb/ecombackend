<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Catalog\ProductDiscovery;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request, ProductDiscovery $discovery): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'required', 'string', 'max:100'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'brand' => ['sometimes', 'required', 'string', 'max:255'],
            'style' => ['sometimes', 'required', 'string', 'max:255'],
            'attribute_value_ids' => ['sometimes', 'array', 'list', 'max:25'],
            'attribute_value_ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
            'min_price' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'],
            'max_price' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991', Rule::when($request->has('min_price'), 'gte:min_price')],
            'in_stock' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'top_selling'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($request->routeIs('api.v1.storefront.collections.*')) {
            $filters['sort'] = $request->routeIs('api.v1.storefront.collections.top-selling') ? 'top_selling' : 'newest';
        }

        $items = $discovery->query($filters)->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, ProductResource::collection($items), 'Products retrieved.', meta: [
            'pagination' => [
                'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $slug, ProductDiscovery $discovery): JsonResponse
    {
        return $this->apiSuccess($request, new ProductResource($discovery->detail($slug)), 'Product retrieved.');
    }

    public function related(Request $request, string $slug, ProductDiscovery $discovery): JsonResponse
    {
        $product = $discovery->detail($slug);
        $categoryIds = $product->categories->modelKeys();
        $related = $discovery->query()->where('products.id', '!=', $product->id)
            ->whereHas('categories', fn (Builder $category) => $category->whereKey($categoryIds))->limit(8)->get();

        return $this->apiSuccess($request, ProductResource::collection($related), 'Related products retrieved.');
    }
}
