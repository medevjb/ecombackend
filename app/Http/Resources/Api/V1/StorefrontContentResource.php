<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StorefrontContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StorefrontContent */
class StorefrontContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $staff = $request->routeIs('api.v1.admin.*');

        return [
            'id' => $this->public_id,
            'key' => $this->key,
            'sort_order' => $this->sort_order,
            'data' => (object) $this->data,
            'published' => $this->when($staff, $this->published),
            'starts_at' => $this->when($staff, $this->starts_at?->toISOString()),
            'ends_at' => $this->when($staff, $this->ends_at?->toISOString()),
        ];
    }
}
