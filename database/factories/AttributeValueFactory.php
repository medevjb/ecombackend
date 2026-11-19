<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AttributeValue> */
class AttributeValueFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return ['attribute_id' => ProductAttribute::factory(), 'name' => $name, 'slug' => Str::slug($name), 'status' => CatalogStatus::Active];
    }
}
