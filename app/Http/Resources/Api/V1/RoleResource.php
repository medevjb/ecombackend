<?php

namespace App\Http\Resources\Api\V1;

use HasinHayder\Tyro\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $slug = $this->resource->getAttribute('slug');

        return [
            'id' => $slug,
            'slug' => $slug,
            'name' => $this->resource->getAttribute('name'),
            'protected' => $slug === 'super-admin',
            'permissions' => $this->privileges->pluck('slug')->values()->all(),
        ];
    }
}
