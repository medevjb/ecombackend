<?php

use App\Enums\CatalogStatus;
use App\Enums\ProductStatus;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('creates a draft with a default SKU and requires valid prices and a category to publish', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('product-manager'));
    $draft = $this->postJson('/api/v1/admin/products', [
        'name' => 'Cotton Shirt',
        'slug' => 'cotton-shirt',
        'sku' => 'cot-100',
        'regular_amount' => 4800,
        'sale_amount' => 3600,
        'status' => 'active',
    ])->assertCreated()->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.discount_percent', 25)->json('data.id');
    $this->assertDatabaseHas('product_variants', ['sku' => 'COT-100', 'is_default' => true]);

    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/products/'.$draft.'/status', ['status' => 'active'])
        ->assertUnprocessable()->assertJsonValidationErrors('status', 'errors.fields');

    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/products/'.$draft, ['regular_amount' => 3500])
        ->assertUnprocessable()->assertJsonValidationErrors('sale_amount', 'errors.fields');

    Auth::forgetGuards();
    $category = Category::factory()->create();
    $this->patchJson('/api/v1/admin/products/'.$draft, ['category_ids' => [$category->public_id]])->assertOk();
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/products/'.$draft.'/status', ['status' => 'active'])->assertOk();
    $this->getJson('/api/v1/products/cotton-shirt')->assertOk()
        ->assertJsonPath('data.current_amount', 3600)->assertJsonPath('data.variants.0.sku', 'COT-100');

    Auth::forgetGuards();
    $this->deleteJson('/api/v1/admin/products/'.$draft)->assertNoContent();
    $this->getJson('/api/v1/products/cotton-shirt')->assertNotFound();
});

it('rejects duplicate SKUs and option combinations and scopes variants to a product', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('product-manager'));
    [$product, $different] = Product::factory()->count(2)->create()->all();
    $attribute = ProductAttribute::factory()->create();
    [$small, $large] = AttributeValue::factory()->for($attribute, 'attribute')->count(2)->create()->all();
    $base = '/api/v1/admin/products/'.$product->public_id.'/variants';

    $variant = $this->postJson($base, ['sku' => 'one', 'option_ids' => [$small->public_id]])->assertCreated()
        ->assertJsonPath('data.sku', 'ONE')->json('data.id');

    Auth::forgetGuards();
    $this->postJson($base, ['sku' => 'two', 'option_ids' => [$small->public_id]])->assertUnprocessable()
        ->assertJsonValidationErrors('option_ids', 'errors.fields');
    Auth::forgetGuards();
    $this->postJson($base, ['sku' => 'ONE', 'option_ids' => [$large->public_id]])->assertUnprocessable()
        ->assertJsonValidationErrors('sku', 'errors.fields');
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/products/'.$different->public_id.'/variants/'.$variant, ['sku' => 'OTHER'])
        ->assertNotFound();
});

it('validates files, keeps one primary image, and retains historical files', function () {
    $this->seed(StaffAccessSeeder::class);
    Storage::fake('public');
    $this->withToken(catalogStaffToken('product-manager'))->withHeader('Accept', 'application/json');
    $product = Product::factory()->create();
    $base = '/api/v1/admin/products/'.$product->public_id.'/images';

    $this->post($base, ['image' => UploadedFile::fake()->create('test.svg', 4, 'image/svg+xml')])->assertUnprocessable();
    Auth::forgetGuards();
    $first = $this->post($base, ['image' => UploadedFile::fake()->image('first.jpg')])->assertCreated()
        ->assertJsonPath('data.is_primary', true)->json('data.id');
    Auth::forgetGuards();
    $second = $this->post($base, [
        'image' => UploadedFile::fake()->image('second.png'),
        'primary' => '1',
    ])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');
    $deleted = ProductImage::query()->where('public_id', $second)->firstOrFail();

    expect($product->images()->where('is_primary', true)->count())->toBe(1);
    Auth::forgetGuards();
    $this->patchJson($base.'/reorder', ['ids' => [$second, $first]])->assertNoContent();
    Auth::forgetGuards();
    $this->deleteJson($base.'/'.$second)->assertNoContent();

    Storage::disk('public')->assertExists($deleted->path);
    expect($product->images()->where('is_primary', true)->firstOrFail()->public_id)->toBe($first);
});

it('combines storefront price, category, brand and stock filters', function () {
    $root = Category::factory()->create(['slug' => 'clothing']);
    $child = Category::factory()->for($root, 'parent')->create(['slug' => 'shirts']);
    $brand = Brand::factory()->create(['slug' => 'example']);
    $one = Product::factory()->for($brand)->create([
        'name' => 'Linen Shirt', 'slug' => 'linen-shirt', 'status' => ProductStatus::Active,
        'regular_amount' => 3500, 'sale_amount' => 2700, 'published_at' => now(),
    ]);
    $one->categories()->attach($child);
    $stocked = ProductVariant::factory()->for($one)->create();
    Inventory::factory()->for($stocked, 'variant')->create(['on_hand' => 4, 'reserved' => 1]);
    ProductVariant::factory()->for($one)->create();
    $other = Product::factory()->for($brand)->create(['status' => ProductStatus::Active, 'published_at' => now()]);
    $other->categories()->attach($child);
    ProductVariant::factory()->for($other)->create();

    $this->getJson('/api/v1/products?search=linen&category=clothing&brand=example&max_price=3000&in_stock=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $one->public_id)
        ->assertJsonPath('data.0.in_stock', true);
    $this->getJson('/api/v1/products?in_stock=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->public_id);

    $root->forceFill(['status' => CatalogStatus::Inactive])->save();
    $this->getJson('/api/v1/products/linen-shirt')->assertNotFound();
});

it('orders top sellers by the paid projection and maps ratings', function () {
    $category = Category::factory()->create();
    [$bestseller, $newer] = Product::factory()->count(2)->create(['status' => ProductStatus::Active, 'published_at' => now()])->all();
    foreach ([$bestseller, $newer] as $product) {
        $product->categories()->attach($category);
        ProductVariant::factory()->for($product)->create();
    }
    DB::table('product_metrics')->insert([
        'product_id' => $bestseller->id, 'paid_quantity' => 10, 'approved_rating_count' => 2, 'approved_rating_total' => 9,
    ]);

    $this->getJson('/api/v1/collections/top-selling')
        ->assertOk()->assertJsonPath('data.0.id', $bestseller->public_id)->assertJsonPath('data.0.rating_average', 4.5);
    $this->getJson('/api/v1/collections/new-arrivals')->assertOk()->assertJsonPath('data.0.id', $newer->public_id);
});
