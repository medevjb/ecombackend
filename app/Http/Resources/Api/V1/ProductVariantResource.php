<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'is_default' => $this->is_default,
            'status' => $this->status->value,
            'price_override_amount' => $this->price_override_amount,
            'in_stock' => $this->whenHas('in_stock'),
            'options' => $this->whenLoaded('values', fn ($values) => TaxonomyResource::collection($values)),
        ];
    }
}
