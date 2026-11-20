<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StorefrontProductSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $clothing = Category::query()->where('slug', 'clothing')->firstOrFail();
        $samples = json_decode(file_get_contents(__DIR__.'/storefront/products.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
        foreach ($samples as $sample) {
            DB::transaction(function () use ($clothing, $sample): void {
                $product = Product::query()->withTrashed()->firstOrCreate(['slug' => $sample['slug']], [
                    'name' => $sample['name'], 'description' => $sample['description'],
                    'regular_amount' => $sample['regular_amount'], 'sale_amount' => $sample['sale_amount'],
                ]);
                if (! $product->wasRecentlyCreated) {
                    return;
                }

                $category = Category::query()->firstOrCreate(['slug' => $sample['category']], [
                    'name' => Str::headline($sample['category']),
                ]);
                if ($category->wasRecentlyCreated) {
                    $category->forceFill(['parent_id' => $clothing->id, 'status' => CatalogStatus::Active])->save();
                }
                $product->categories()->attach($category);
                $product->forceFill(['status' => ProductStatus::Active, 'published_at' => now()])->save();

                $sku = 'STOREFRONT-'.strtoupper($sample['slug']);
                $hasOptions = isset($sample['colors'], $sample['sizes']);
                $default = $product->variants()->make()->forceFill([
                    'sku' => $sku, 'is_default' => true, 'status' => CatalogStatus::Active,
                    'combination_key' => hash('sha256', ''),
                ]);
                $default->save();
                $default->inventory()->create(['on_hand' => $hasOptions ? 0 : 25]);

                foreach ($sample['images'] as $index => $name) {
                    $path = 'demo-catalog/'.$name;
                    $contents = file_get_contents(__DIR__.'/storefront/images/'.$name);
                    if ($contents === false || ! Storage::disk('public')->put($path, $contents)) {
                        throw new RuntimeException('Could not copy demo catalog image '.$name);
                    }
                    $product->images()->make()->forceFill([
                        'disk' => 'public', 'path' => $path, 'alt_text' => $sample['name'],
                        'sort_order' => $index, 'is_primary' => $index === 0,
                    ])->save();
                }

                if ($hasOptions) {
                    $this->addOptions($product, $sku, $sample['colors'], $sample['sizes']);
                }
            });
        }
    }

    /** @param list<string> $colors
     * @param  list<string>  $sizes
     */
    private function addOptions(Product $product, string $sku, array $colors, array $sizes): void
    {
        $options = ['color' => [], 'size' => []];
        foreach (['color' => $colors, 'size' => $sizes] as $slug => $names) {
            $attribute = ProductAttribute::query()->where('slug', $slug)->firstOrFail();
            foreach ($names as $name) {
                $value = $attribute->values()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
                if ($value->wasRecentlyCreated) {
                    $value->forceFill(['status' => CatalogStatus::Active])->save();
                }
                $options[$slug][] = $value;
            }
        }
        foreach ($options['color'] as $color) {
            foreach ($options['size'] as $size) {
                $ids = [$color->id, $size->id];
                sort($ids, SORT_NUMERIC);
                $variant = $product->variants()->make()->forceFill([
                    'sku' => strtoupper($sku.'-'.$color->slug.'-'.$size->slug),
                    'status' => CatalogStatus::Active, 'combination_key' => hash('sha256', implode(':', $ids)),
                ]);
                $variant->save();
                $variant->values()->attach([
                    $color->id => ['attribute_id' => $color->attribute_id],
                    $size->id => ['attribute_id' => $size->attribute_id],
                ]);
                $variant->inventory()->create(['on_hand' => 10]);
            }
        }
    }
}
