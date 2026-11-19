<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductReviewResource;
use App\Models\ProductReview;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\ProductReviews;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CatalogMaintenance $pagination): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ReviewStatus::class)], 'product_id' => ['sometimes', 'ulid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ProductReview::query()->with(['product' => fn ($product) => $product->withTrashed()->select('id', 'public_id'), 'author:id,public_id']);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['product_id'])) {
            $query->whereHas('product', fn (Builder $product) => $product->withTrashed()->where('public_id', $data['product_id']));
        }
        $items = $query->latest('id')->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, ProductReviewResource::collection($items), 'Reviews retrieved.', meta: $pagination->meta($items))
            ->header('Cache-Control', 'no-store');
    }

    public function moderate(Request $request, string $review, ProductReviews $reviews): JsonResponse
    {
        return $this->apply($request, $reviews, [$review]);
    }

    public function bulk(Request $request, ProductReviews $reviews): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'list', 'min:1', 'max:50'], 'ids.*' => ['required', 'ulid', 'distinct:ignore_case']]);

        return $this->apply($request, $reviews, array_values($data['ids']));
    }

    /** @param list<string> $ids */
    private function apply(Request $request, ProductReviews $reviews, array $ids): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ReviewStatus::class)->only([ReviewStatus::Approved, ReviewStatus::Rejected])],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $count = $reviews->moderate($ids, ReviewStatus::from($data['status']), $data['reason']);

        return $this->apiSuccess($request, ['changed' => $count], 'Review moderation recorded.');
    }
}
