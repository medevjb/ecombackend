<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sku = fake()->unique()->bothify('SKU-#####-????');

        return ['product_id' => Product::factory(), 'sku' => $sku, 'status' => CatalogStatus::Active, 'combination_key' => hash('sha256', $sku)];
    }
}
