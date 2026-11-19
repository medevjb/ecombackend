<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductAttribute> */
class ProductAttributeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return ['name' => $name, 'slug' => Str::slug($name), 'status' => CatalogStatus::Active];
    }
}
