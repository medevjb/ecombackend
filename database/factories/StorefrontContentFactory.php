<?php

namespace Database\Factories;

use App\Models\StorefrontContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StorefrontContent> */
class StorefrontContentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => 'banner', 'key' => fake()->unique()->slug(3), 'data' => ['placement' => 'hero', 'title' => fake()->sentence()],
            'published' => true, 'sort_order' => 0,
        ];
    }
}
