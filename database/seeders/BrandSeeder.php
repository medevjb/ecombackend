<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brand = Brand::query()->withTrashed()->firstOrCreate(['slug' => 'example-label'], ['name' => 'Example Label']);

        if ($brand->wasRecentlyCreated) {
            $brand->forceFill(['status' => CatalogStatus::Active])->save();
        }
    }
}
