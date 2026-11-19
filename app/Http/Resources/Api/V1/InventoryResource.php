<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inventory */
class InventoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->variant;
        $threshold = config()->integer('inventory.low_stock_threshold');

        return [
            'variant_id' => $variant?->public_id,
            'sku' => $variant?->sku,
            'product_id' => $variant?->getRelation('product')?->public_id,
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available(),
            'low_stock' => $this->available() <= $threshold,
        ];
    }
}
