<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(StaffAccessSeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call([
                DemoAccountSeeder::class,
                CategorySeeder::class,
                BrandSeeder::class,
                ProductAttributeSeeder::class,
                ProductSeeder::class,
                StorefrontProductSeeder::class,
                CouponSeeder::class,
                StorefrontContentSeeder::class,
                DemoStorefrontBrandingSeeder::class,
                DemoMediaSeeder::class,
                DemoOrderSeeder::class,
            ]);
        }
    }
}
