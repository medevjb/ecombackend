<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Brand;
use App\Models\CatalogRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogRecord */
class TaxonomyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $brand = $this->resource instanceof Brand ? $this->resource : null;

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            'description' => $this->when($brand !== null, $brand?->description),
            'logo_url' => $this->when($brand !== null, $brand?->logo_url),
            'values' => $this->whenLoaded('values', fn ($values) => self::collection($values)),
            'deleted_at' => $this->when($request->routeIs('api.v1.admin.*'), fn (): ?string => $this->deleted_at?->toISOString()),
        ];
    }
}
