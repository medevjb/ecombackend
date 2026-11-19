<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $user_id
 * @property OrderStatus $status
 * @property string $currency
 * @property int $total_amount
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $shipping_amount
 * @property int $tax_amount
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property-read Payment $payment
 * @property-read CheckoutAttempt $checkoutAttempt
 * @property-read User $user
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class, 'paid_at' => 'datetime', 'total_amount' => 'integer',
            'subtotal_amount' => 'integer', 'discount_amount' => 'integer', 'shipping_amount' => 'integer', 'tax_amount' => 'integer',
        ];
    }

    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** @return BelongsTo<CheckoutAttempt, $this> */
    public function checkoutAttempt(): BelongsTo
    {
        return $this->belongsTo(CheckoutAttempt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
