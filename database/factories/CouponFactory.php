<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Coupon> */
class CouponFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['code' => Str::upper(fake()->unique()->bothify('DEMO-????-####')), 'type' => CouponType::Fixed,
            'value' => 500, 'currency' => 'USD', 'status' => CatalogStatus::Active];
    }
}
