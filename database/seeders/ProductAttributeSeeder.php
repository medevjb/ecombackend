<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Models\ProductAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductAttributeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Size' => ['Small', 'Medium', 'Large'], 'Color' => ['Black', 'White']] as $name => $values) {
            $attribute = ProductAttribute::query()->withTrashed()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);

            if ($attribute->wasRecentlyCreated) {
                $attribute->forceFill(['status' => CatalogStatus::Active])->save();
            }

            foreach ($values as $value) {
                $record = $attribute->values()->withTrashed()->firstOrCreate(['slug' => Str::slug($value)], ['name' => $value]);

                if ($record->wasRecentlyCreated) {
                    $record->forceFill(['status' => CatalogStatus::Active])->save();
                }
            }
        }
    }
}
