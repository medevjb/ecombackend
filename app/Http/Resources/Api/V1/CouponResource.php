<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'type' => $this->type->value,
            'value' => $this->type === CouponType::Percentage ? $this->value / 100 : $this->value,
            'currency' => $this->currency,
            'minimum_amount' => $this->minimum_amount,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'status' => $this->status->value,
            'global_limit' => $this->global_limit,
            'per_customer_limit' => $this->per_customer_limit,
            'product_ids' => $this->whenLoaded('products', fn ($products) => $products->pluck('public_id')->values()),
            'category_ids' => $this->whenLoaded('categories', fn ($categories) => $categories->pluck('public_id')->values()),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
