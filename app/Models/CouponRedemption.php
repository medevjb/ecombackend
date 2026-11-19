<?php

namespace App\Models;

use App\Enums\CouponUsageStatus;
use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $coupon_id
 * @property int $user_id
 * @property string $fingerprint
 * @property CouponUsageStatus $status
 * @property int $discount_amount
 * @property Carbon $expires_at
 */
class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CouponUsageStatus::class,
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'discount_amount' => 'integer',
        ];
    }
}
