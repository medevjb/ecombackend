<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return ['name' => $name, 'slug' => Str::slug($name), 'regular_amount' => 4900, 'currency' => 'USD', 'status' => ProductStatus::Draft];
    }
}
