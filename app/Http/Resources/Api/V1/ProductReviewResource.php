<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductReview */
class ProductReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'verified_purchase' => $this->verified_purchase,
            'created_at' => $this->created_at->toISOString(),
            'status' => $this->when(! $request->routeIs('api.v1.storefront.reviews.index'), $this->status->value),
            'product_id' => $this->whenLoaded('product', fn () => $this->product->public_id),
            'customer_id' => $this->whenLoaded('author', fn () => $this->author->public_id),
        ];
    }
}
