<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        if (! Coupon::query()->withTrashed()->where('code', 'DEMO10')->exists()) {
            (new Coupon)->forceFill([
                'code' => 'DEMO10', 'type' => CouponType::Percentage, 'value' => 1000, 'status' => CatalogStatus::Active,
            ])->save();
        }
    }
}
