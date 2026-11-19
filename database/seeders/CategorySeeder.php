<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $clothing = Category::query()->withTrashed()->firstOrCreate(['slug' => 'clothing'], ['name' => 'Clothing']);

        if ($clothing->wasRecentlyCreated) {
            $clothing->forceFill(['status' => CatalogStatus::Active])->save();
        }

        $shirts = Category::query()->withTrashed()->firstOrCreate(['slug' => 'shirts'], ['name' => 'Shirts']);

        if ($shirts->wasRecentlyCreated) {
            $shirts->forceFill(['parent_id' => $clothing->id, 'status' => CatalogStatus::Active])->save();
        }
    }
}
