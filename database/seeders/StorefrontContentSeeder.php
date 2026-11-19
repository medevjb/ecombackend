<?php

namespace Database\Seeders;

use App\Models\StorefrontContent;
use Illuminate\Database\Seeder;

class StorefrontContentSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            ['new-arrivals', 'New Arrivals', 'new_arrivals'],
            ['top-selling', 'Top Selling', 'top_selling'],
            ['brands', 'Shop by Brand', 'brands'],
            ['featured-categories', 'Shop by Category', 'categories'],
            ['dress-styles', 'Browse by Dress Style', 'tiles'],
        ];
        foreach ($sections as $position => [$key, $title, $strategy]) {
            $data = ['title' => $title, 'strategy' => $strategy];
            if ($strategy === 'tiles') {
                $data['items'] = array_map(
                    fn (string $style): array => ['title' => $style, 'link_url' => '/products?category='.strtolower($style)],
                    ['Casual', 'Formal', 'Party', 'Gym'],
                );
            }
            StorefrontContent::query()->firstOrCreate(['type' => 'section', 'key' => $key], [
                'data' => $data, 'published' => true, 'sort_order' => $position,
            ]);
        }
    }
}
