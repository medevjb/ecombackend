<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoMediaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $disk = Storage::disk('public');
        foreach (['t-shirts' => 'shirt.png', 'shirts' => 'shirt-full.png', 'jeans' => 'pent.png', 'shorts' => 'shortpent.png'] as $slug => $filename) {
            $path = 'demo-catalog/'.$filename;
            Category::query()->where('slug', $slug)->whereNull('image_url')->update(['image_url' => $disk->url($path)]);
        }

        foreach ([
            'cotton-t-shirt' => 'tshirt2.png', 'linen-shirt' => 'shirt-full.png', 'striped-tee' => 'striped-tee.png',
            'oxford-shirt' => 'shirtfull2.png', 'denim-shirt' => 'shirt.png',
        ] as $slug => $filename) {
            $product = Product::query()->where('slug', $slug)->whereDoesntHave('images')->first();
            if ($product !== null) {
                $path = 'demo-catalog/'.$filename;
                if (! $disk->exists($path)) {
                    $contents = file_get_contents(__DIR__.'/storefront/images/'.$filename);
                    if ($contents === false || ! $disk->put($path, $contents)) {
                        throw new RuntimeException('Could not copy demo catalog image '.$filename);
                    }
                }
                $product->images()->make()->forceFill([
                    'disk' => 'public', 'path' => $path, 'alt_text' => $product->name,
                    'sort_order' => 0, 'is_primary' => true,
                ])->save();
            }
        }

        if (! app()->environment('local') || is_dir(public_path('storage'))) {
            return;
        }

        $exit = Artisan::call('storage:link', ['--no-interaction' => true]);
        if ($exit !== 0 || ! is_dir(public_path('storage'))) {
            throw new RuntimeException('The public storage link could not be created.');
        }
    }
}
