<?php

namespace Database\Seeders;

use App\Models\Category;
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

        if (! app()->environment('local') || is_dir(public_path('storage'))) {
            return;
        }

        $exit = Artisan::call('storage:link', ['--no-interaction' => true]);
        if ($exit !== 0 || ! is_dir(public_path('storage'))) {
            throw new RuntimeException('The public storage link could not be created.');
        }
    }
}
