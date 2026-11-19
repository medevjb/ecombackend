<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::query()->where('slug', 'shirts')->firstOrFail();
        $brand = Brand::query()->where('slug', 'example-label')->firstOrFail();
        $names = ['Cotton T-Shirt', 'Linen Shirt', 'Striped Tee', 'Oxford Shirt', 'Denim Shirt'];

        DB::transaction(function () use ($category, $brand, $names): void {
            foreach ($names as $index => $name) {
                $product = Product::query()->withTrashed()->firstOrCreate(['slug' => Str::slug($name)], [
                    'name' => $name,
                    'regular_amount' => 2900 + $index * 500,
                    'short_description' => 'Sample catalog product for local development.',
                ]);

                if ($product->wasRecentlyCreated) {
                    $product->forceFill(['brand_id' => $brand->id, 'status' => ProductStatus::Active, 'published_at' => now()])->save();
                    $product->categories()->attach($category);
                    $product->variants()->make()->forceFill([
                        'sku' => 'DEMO-'.($index + 1),
                        'is_default' => true,
                        'status' => CatalogStatus::Active,
                        'combination_key' => hash('sha256', ''),
                    ])->save();
                }
            }
        });
    }
}
