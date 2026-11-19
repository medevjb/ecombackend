<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductReviewResource;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\ProductDiscovery;
use App\Services\Catalog\ProductReviews;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $slug, ProductDiscovery $products, CatalogMaintenance $pagination): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $product = $products->query()->where('products.slug', $slug)->firstOrFail();
        $items = ProductReview::query()->where('product_id', $product->id)->where('status', ReviewStatus::Approved)
            ->latest('created_at')->latest('id')->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, ProductReviewResource::collection($items), 'Product reviews retrieved.', meta: $pagination->meta($items));
    }

    public function store(Request $request, string $slug, ProductDiscovery $products, ProductReviews $reviews): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $product = $products->query()->where('products.slug', $slug)->firstOrFail();
        $review = $reviews->submit($product, $this->customer($request), $data);

        return $this->apiSuccess($request, new ProductReviewResource($review), 'Review submitted for moderation.', 201);
    }

    public function update(Request $request, string $review, ProductReviews $reviews): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $record = $reviews->edit($review, $this->customer($request), $data);

        return $this->apiSuccess($request, new ProductReviewResource($record), 'Review updated.');
    }

    public function destroy(Request $request, string $review, ProductReviews $reviews): JsonResponse
    {
        $reviews->delete($review, $this->customer($request));

        return response()->json(null, 204);
    }

    private function customer(Request $request): User
    {
        $user = $request->user('customer');
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        return [
            'rating' => [$creating ? 'required' : 'sometimes', 'integer', 'between:1,5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'verified_purchase' => ['prohibited'],
        ];
    }
}
