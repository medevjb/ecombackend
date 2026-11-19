<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerAddress */
class CustomerAddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id] + $this->only([
            'recipient', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone', 'is_default',
        ]);
    }
}
