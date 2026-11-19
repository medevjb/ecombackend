<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array{id: string, name: string, email: string, email_verified: bool, roles: list<string>} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'roles' => array_values(array_filter($this->loadMissing('roles')->roles->pluck('slug')->all(), 'is_string')),
        ];
    }
}
