<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\ProductDiscovery;
use App\Traits\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    use ApiResponse;

    public function index(Request $request, ProductDiscovery $products, CatalogMaintenance $pagination): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $items = $products->query()->join('customer_wishlists', 'customer_wishlists.product_id', '=', 'products.id')
            ->where('customer_wishlists.user_id', $this->customer($request)->id)
            ->reorder('customer_wishlists.created_at', 'desc')->orderByDesc('products.id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, ProductResource::collection($items), 'Wishlist retrieved.', meta: $pagination->meta($items))
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $product): JsonResponse
    {
        return $this->apiSuccess($request, ['saved' => $this->item($request, $product)->exists()], 'Wishlist status retrieved.')
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $product, ProductDiscovery $products): Response
    {
        $customer = $this->customer($request);
        $record = $products->query()->where('products.public_id', $product)->firstOrFail();
        DB::table('customer_wishlists')->insertOrIgnore(['user_id' => $customer->id, 'product_id' => $record->id, 'created_at' => now()]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $product): Response
    {
        $this->item($request, $product)->delete();

        return response()->noContent();
    }

    private function item(Request $request, string $product): Builder
    {
        return DB::table('customer_wishlists')->where('user_id', $this->customer($request)->id)
            ->whereIn('product_id', Product::withTrashed()->select('id')->where('public_id', $product));
    }

    private function customer(Request $request): User
    {
        $customer = $request->user();
        abort_unless($customer instanceof User, 401);

        return $customer;
    }
}
