<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderRefund */
class OrderRefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'purpose' => $this->purpose,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
