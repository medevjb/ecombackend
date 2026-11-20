<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'payment_status' => $this->payment->status->value,
            'cancellation_refund_pending' => $this->whenHas('cancellation_refund_pending'),
            'captured_amount' => $this->when($request->routeIs('api.v1.admin.orders.*'), fn (): int => $this->payment->captured_amount),
            'currency' => $this->currency,
            'subtotal_amount' => $this->subtotal_amount,
            'discount_amount' => $this->discount_amount,
            'shipping_amount' => $this->shipping_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'coupon_code' => $this->resource->getAttribute('coupon_code'),
            'shipping_carrier' => $this->resource->getAttribute('shipping_carrier'),
            'tracking_number' => $this->resource->getAttribute('tracking_number'),
            'items' => $this->whenLoaded('snapshotItems'),
            'address' => $this->whenLoaded('snapshotAddress'),
            'history' => $this->whenLoaded('snapshotHistory'),
        ];
    }
}
