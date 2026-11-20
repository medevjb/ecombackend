<?php

namespace Database\Seeders;

use App\Models\StorefrontContent;
use Illuminate\Database\Seeder;

class StorefrontContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $sections = [
            ['new-arrivals', 'New Arrivals', 'new_arrivals'],
            ['top-selling', 'Top Selling', 'top_selling'],
            ['brands', 'Shop by Brand', 'brands'],
            ['dress-styles', 'Browse by Dress Style', 'tiles'],
        ];
        foreach ($sections as $position => [$key, $title, $strategy]) {
            $data = ['title' => $title, 'strategy' => $strategy];
            if ($strategy === 'tiles') {
                $data['items'] = array_map(
                    fn (string $style): array => ['title' => $style, 'link_url' => '/products?style='.strtolower($style), 'image_url' => '/category-images/'.strtolower($style).'.png', 'image_alt' => $style.' fashion'],
                    ['Casual', 'Formal', 'Party', 'Gym'],
                );
            }
            $section = StorefrontContent::query()->firstOrCreate(['type' => 'section', 'key' => $key], [
                'data' => $data, 'published' => true, 'sort_order' => $position,
            ]);
            if ($strategy === 'tiles') {
                $legacy = ['title' => $title, 'strategy' => $strategy, 'items' => array_map(
                    fn (string $style): array => ['title' => $style, 'link_url' => '/products?category='.strtolower($style)],
                    ['Casual', 'Formal', 'Party', 'Gym'],
                )];
                $previous = $data;
                $previous['items'] = array_map(
                    fn (array $item): array => array_replace($item, ['link_url' => '/products']),
                    $data['items'],
                );
                if ($section->data == $legacy || $section->data == $previous) {
                    $section->update(['data' => $data]);
                }
            }
        }
    }
}
