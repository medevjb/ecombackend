<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            'parent_id' => $this->whenLoaded('parent', fn (): ?string => $this->parent?->public_id),
            'children' => $this->whenLoaded('children', fn () => self::collection($this->children)),
            'deleted_at' => $this->when($request->routeIs('api.v1.admin.*'), fn (): ?string => $this->deleted_at?->toISOString()),
        ];
    }
}
