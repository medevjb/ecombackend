<?php

namespace Database\Factories;

use App\Enums\CouponUsageStatus;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CouponRedemption> */
class CouponRedemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(), 'user_id' => User::factory(),
            'order_reference' => strtolower((string) Str::ulid()),
            'fingerprint' => hash('sha256', fake()->uuid()), 'discount_amount' => 500,
            'status' => CouponUsageStatus::Reserved, 'expires_at' => now()->addMinutes(15),
        ];
    }
}
