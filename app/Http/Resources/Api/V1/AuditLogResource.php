<?php

namespace App\Http\Resources\Api\V1;

use HasinHayder\Tyro\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->only(['id', 'user_id', 'event', 'auditable_type', 'auditable_id', 'created_at']);
    }
}
