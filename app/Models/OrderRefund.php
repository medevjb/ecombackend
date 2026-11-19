<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\OrderRefundFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $actor_id
 * @property int $amount
 * @property string $currency
 * @property string $public_id
 * @property string $purpose
 * @property string $reason
 * @property string|null $provider_refund_id
 * @property RefundStatus $status
 * @property Carbon|null $notified_at
 * @property Carbon $created_at
 * @property-read Order $order
 */
class OrderRefund extends Model
{
    /** @use HasFactory<OrderRefundFactory> */
    use HasFactory, HasUlids;

    protected $table = 'refunds';

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => RefundStatus::class, 'amount' => 'integer', 'notified_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
