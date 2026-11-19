<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Auth\StaffAccess;
use Illuminate\Http\Request;

/** @mixin User */
class StaffResource extends UserResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'active' => ! $this->isSuspended(),
            'permissions' => app(StaffAccess::class)->permissions($this->resource),
        ];
    }
}
