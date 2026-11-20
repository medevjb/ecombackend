<?php

use App\Models\StorefrontContent;
use Database\Seeders\StaffAccessSeeder;
use Database\Seeders\StorefrontContentSeeder;
use Illuminate\Support\Facades\Auth;

it('serves ordered published content and current catalog data on the homepage', function () {
    $this->seed(StorefrontContentSeeder::class);
    [, , $product] = checkoutFixture();
    StorefrontContent::factory()->create(['key' => 'second', 'sort_order' => 10]);
    StorefrontContent::factory()->create(['key' => 'first', 'sort_order' => 2]);
    StorefrontContent::factory()->create(['key' => 'future', 'starts_at' => now()->addDay()]);
    StorefrontContent::factory()->create(['key' => 'draft', 'published' => false]);
    StorefrontContent::factory()->create(['key' => 'expired', 'ends_at' => now()->subDay()]);

    $this->getJson('/api/v1/storefront/home')->assertOk()
        ->assertJsonCount(2, 'data.hero_slides')
        ->assertJsonPath('data.hero_slides.0.key', 'first')
        ->assertJsonPath('data.hero_slides.1.key', 'second')
        ->assertJsonPath('data.sections.0.entries.0.id', $product->public_id)
        ->assertJsonPath('data.sections.3.key', 'dress-styles')
        ->assertJsonPath('data.sections.3.entries.0.title', 'Casual')
        ->assertJsonMissingPath('data.hero_slides.0.published');
});

it('validates links and keeps staff content operations scoped to their resource', function () {
    $this->seed(StaffAccessSeeder::class);
    $route = '/api/v1/admin/banners';
    $this->withToken(catalogStaffToken('product-manager'))
        ->postJson($route, [])->assertForbidden();
    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('admin'))->postJson($route, [
        'key' => 'hero', 'data' => ['placement' => 'hero', 'title' => 'New products', 'link_url' => 'javascript:alert(1)'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['data.link_url'], 'errors.fields');
    Auth::forgetGuards();
    $id = $this->postJson($route, [
        'key' => 'hero', 'data' => ['placement' => 'hero', 'title' => 'New products', 'link_url' => '/products'],
    ])->assertCreated()->assertJsonPath('data.published', false)->json('data.id');
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/navigation/'.$id)->assertNotFound();
    Auth::forgetGuards();
    $this->patchJson($route.'/'.$id, ['published' => true])->assertOk()->assertJsonPath('data.published', true);
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/site-settings', ['data' => ['image_fallback_url' => '/placeholder.webp']])->assertOk();
    $this->getJson('/api/v1/storefront/settings')->assertOk()->assertJsonPath('data.image_fallback_url', '/placeholder.webp');
});
