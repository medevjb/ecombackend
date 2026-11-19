<?php

namespace App\Http\Resources\Api\V1;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovement */
class InventoryMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'delta' => $this->delta,
            'on_hand_after' => $this->on_hand_after,
            'reason' => $this->reason,
            'created_at' => $this->created_at->toISOString(),
            'variant_id' => $this->inventory->variant?->public_id,
        ];
    }
}
