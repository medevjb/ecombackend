<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sale = $this->sale_amount;
        $regular = $this->regular_amount;

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->resource->getAttribute('description'),
            'short_description' => $this->resource->getAttribute('short_description'),
            'regular_amount' => $regular,
            'sale_amount' => $sale,
            'current_amount' => $sale ?? $regular,
            'discount_percent' => $sale === null || $regular <= 0 ? 0 : intdiv(($regular - $sale) * 100, $regular),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'featured' => (bool) $this->resource->getAttribute('featured'),
            'seo_title' => $this->resource->getAttribute('seo_title'),
            'seo_description' => $this->resource->getAttribute('seo_description'),
            'published_at' => $this->published_at?->toISOString(),
            'brand' => $this->whenLoaded('brand', fn ($brand) => new TaxonomyResource($brand)),
            'categories' => $this->whenLoaded('categories', fn ($categories) => CategoryResource::collection($categories)),
            'variants' => $this->whenLoaded('variants', fn ($variants) => ProductVariantResource::collection($variants)),
            'images' => $this->whenLoaded('images', fn ($images) => ProductImageResource::collection($images)),
            'in_stock' => $this->whenHas('in_stock'),
            'rating_count' => $this->whenHas('rating_count', fn ($count): int => (int) $count),
            'rating_average' => $this->whenHas('rating_count', fn ($count): ?float => $count > 0
                ? round((int) $this->resource->getAttribute('rating_total') / (int) $count, 2)
                : null),
            'deleted_at' => $this->when($request->routeIs('api.v1.admin.*'), fn (): ?string => $this->deleted_at?->toISOString()),
        ];
    }
}
