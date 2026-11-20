<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StorefrontContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoStorefrontBrandingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $hero = [
            'placement' => 'hero',
            'title' => 'Find clothes that match your style',
            'body' => 'Discover everyday essentials and statement pieces.',
            'image_url' => '/hero-right-image-full.png',
            'image_alt' => 'Models in everyday fashion',
            'link_url' => '/products',
            'link_label' => 'Shop now',
        ];
        $disk = Storage::disk('public');
        $heroPath = 'demo-catalog/shopco-hero.png';
        if (! $disk->exists($heroPath)) {
            $image = file_get_contents(__DIR__.'/storefront/images/shopco-hero.png');
            if ($image === false || ! $disk->put($heroPath, $image)) {
                throw new RuntimeException('Could not seed the storefront hero image.');
            }
        }
        $seededHero = array_replace($hero, ['image_url' => $disk->url($heroPath)]);
        $stats = ['stats' => [
            ['label' => 'International Brands', 'value' => '200+'],
            ['label' => 'High-Quality Products', 'value' => '2,000+'],
            ['label' => 'Happy Customers', 'value' => '30,000+'],
        ]];
        $banner = StorefrontContent::query()->firstOrCreate(['type' => 'banner', 'key' => 'shop-collection'], [
            'data' => $seededHero + $stats, 'published' => true,
        ]);
        if ($banner->data == $hero || $banner->data == ($hero + $stats)) {
            $banner->update(['data' => $seededHero + $stats]);
        }

        StorefrontContent::query()->firstOrCreate(['type' => 'banner', 'key' => 'new-collection-announcement'], [
            'data' => [
                'placement' => 'announcement',
                'title' => 'Explore the latest collection.',
                'link_url' => '/products?collection=new-arrivals',
                'link_label' => 'Shop new arrivals',
            ],
            'published' => true,
        ]);
        StorefrontContent::query()->where('type', 'banner')->where('key', 'everyday-edit-promotion')
            ->where('published', true)->update(['published' => false]);

        foreach ([['shop', 'Shop', '/products'], ['categories', 'Categories', '/categories'], ['new-arrivals', 'New Arrivals', '/products?collection=new-arrivals'], ['top-selling', 'Top Selling', '/products?collection=top-selling']] as $index => [$key, $label, $url]) {
            StorefrontContent::query()->firstOrCreate(['type' => 'navigation', 'key' => $key], [
                'data' => ['placement' => 'header', 'label' => $label, 'url' => $url], 'published' => true, 'sort_order' => $index,
            ]);
        }

        foreach ([
            ['footer-shop', 'Shop', 'Browse catalog', '/products'],
            ['footer-arrivals', 'Shop', 'New arrivals', '/products?collection=new-arrivals'],
            ['footer-popular', 'Shop', 'Top selling', '/products?collection=top-selling'],
            ['footer-categories', 'Explore', 'Product categories', '/categories'],
            ['footer-brands', 'Explore', 'Shop by brand', '/#shop-by-brand'],
            ['footer-styles', 'Explore', 'Dress styles', '/#dress-styles'],
            ['footer-account', 'Account', 'My account', '/account'],
            ['footer-orders', 'Account', 'Order history', '/account/orders'],
            ['footer-addresses', 'Account', 'Saved addresses', '/account/addresses'],
            ['footer-help', 'Help', 'Reset your password', '/forgot-password'],
            ['footer-cart', 'Help', 'Your shopping bag', '/cart'],
        ] as $position => [$key, $column, $label, $url]) {
            StorefrontContent::query()->firstOrCreate(['type' => 'navigation', 'key' => $key], [
                'data' => ['placement' => 'footer', 'column' => $column, 'label' => $label, 'url' => $url],
                'published' => true, 'sort_order' => $position,
            ]);
        }

        foreach ([
            'studio-north' => ['Studio North', ['t-shirt-with-tape-details', 'one-life-graphic-t-shirt', 'courage-graphic-t-shirt', 'gradient-graphic-t-shirt']],
            'indigo-standard' => ['Indigo Standard', ['skinny-fit-jeans', 'faded-skinny-jeans', 'loose-fit-bermuda-shorts']],
            'modern-form' => ['Modern Form', ['checkered-shirt', 'vertical-striped-shirt', 'polo-with-contrast-trims', 'polo-with-tipping-details']],
            'off-duty' => ['Off Duty', ['sleeve-striped-t-shirt', 'black-striped-t-shirt']],
        ] as $slug => [$name, $products]) {
            $brand = Brand::query()->withTrashed()->firstOrCreate(['slug' => $slug], ['name' => $name]);
            if ($brand->wasRecentlyCreated) {
                $brand->forceFill(['status' => CatalogStatus::Active])->save();
            }
            if (! $brand->trashed() && $brand->status === CatalogStatus::Active) {
                Product::query()->whereIn('slug', $products)->whereNull('brand_id')->update(['brand_id' => $brand->id]);
            }
        }

        foreach ([
            ['easy-everyday-style', 'Sarah · Demo shopper', 'The graphic tee is easy to style, and the size options made it simple to pick the fit I wanted.'],
            ['catalog-experience', 'Alex · Demo shopper', 'I could go straight from browsing to the color and size I wanted. The product photos made the choice easier.'],
            ['wardrobe-staples', 'James · Demo shopper', 'I liked finding classic shirts and everyday denim in one place.'],
            ['easy-to-browse', 'Maya · Demo shopper', 'The category pages made it easy to narrow down what I wanted.'],
            ['clear-product-details', 'Jordan · Demo shopper', 'Seeing the size choices and product photos together made it easy to compare styles.'],
        ] as $position => [$key, $author, $quote]) {
            $data = ['author' => $author, 'quote' => $quote, 'source' => 'editorial'];
            $testimonial = StorefrontContent::query()->firstOrCreate(['type' => 'testimonial', 'key' => $key], [
                'data' => $data + ['rating' => 5],
                'published' => true,
                'sort_order' => $position,
            ]);
            if ($testimonial->data == $data) {
                $testimonial->update(['data' => $data + ['rating' => 5]]);
            }
        }

        $root = Category::query()->firstOrCreate(['slug' => 'dress-styles'], ['name' => 'Dress Styles']);
        if ($root->wasRecentlyCreated) {
            $root->forceFill(['status' => CatalogStatus::Active])->save();
        }
        foreach ([
            'casual' => ['t-shirt-with-tape-details', 'skinny-fit-jeans', 'one-life-graphic-t-shirt', 'faded-skinny-jeans', 'loose-fit-bermuda-shorts'],
            'formal' => ['checkered-shirt', 'vertical-striped-shirt', 'polo-with-tipping-details'],
            'party' => ['courage-graphic-t-shirt', 'gradient-graphic-t-shirt', 'polo-with-contrast-trims'],
            'gym' => ['sleeve-striped-t-shirt', 'black-striped-t-shirt', 'loose-fit-bermuda-shorts'],
        ] as $style => $products) {
            $category = Category::query()->firstOrCreate(['slug' => $style], ['name' => ucfirst($style)]);
            if ($category->wasRecentlyCreated) {
                $category->forceFill(['status' => CatalogStatus::Active, 'parent_id' => $root->id])->save();
            }
            if ($category->parent_id === $root->id) {
                Product::query()->whereIn('slug', $products)->each(fn (Product $product) => $product->categories()->syncWithoutDetaching([$category->id]));
            }
        }

        $settings = StorefrontContent::query()->where('type', 'settings')->where('key', 'site')->firstOrFail();
        if ($settings->data === []) {
            $settings->update(['data' => [
                'name' => 'SHOP.CO', 'newsletter_title' => 'Stay up to date with our latest offers',
                'newsletter_copy' => 'Get new collection announcements and store updates.',
                'seo_title' => 'SHOP.CO', 'seo_description' => 'Browse the SHOP.CO fashion catalog.',
                'copyright' => 'SHOP.CO. All rights reserved.',
            ]]);
        }
    }
}
