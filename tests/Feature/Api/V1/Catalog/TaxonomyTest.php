<?php

use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\ProductAttribute;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;

it('exposes only active brands and round trips their lifecycle', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('product-manager'));
    Brand::factory()->create(['name' => 'Hidden', 'status' => CatalogStatus::Inactive]);

    $brand = $this->postJson('/api/v1/admin/brands', [
        'name' => 'Example',
        'slug' => 'example',
        'logo_url' => 'https://example.test/logo.png',
    ])->assertCreated()->assertJsonPath('data.status', 'inactive')->json('data.id');

    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/brands/'.$brand.'/status', ['status' => 'active'])->assertOk();

    $this->getJson('/api/v1/brands')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $brand)
        ->assertJsonPath('data.0.logo_url', 'https://example.test/logo.png');

    Auth::forgetGuards();
    $this->deleteJson('/api/v1/admin/brands/'.$brand)->assertNoContent();
    $this->getJson('/api/v1/brands')->assertJsonCount(0, 'data');

    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/brands/'.$brand.'/restore')->assertOk();
    $this->getJson('/api/v1/brands')->assertJsonPath('data.0.id', $brand);
});

it('scopes option slugs and record access to their attribute', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('product-manager'));
    [$size, $other] = ProductAttribute::factory()->count(2)->create()->all();
    $value = AttributeValue::factory()->for($size, 'attribute')->create(['slug' => 'small']);

    $this->postJson('/api/v1/admin/attributes/'.$size->public_id.'/values', [
        'name' => 'Small',
        'slug' => 'small',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug', 'errors.fields');

    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/attributes/'.$other->public_id.'/values', [
        'name' => 'Small',
        'slug' => 'small',
    ])->assertCreated();

    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/attributes/'.$other->public_id.'/values/'.$value->public_id)->assertNotFound();

    $this->getJson('/api/v1/attributes')->assertJsonPath('data.0.values.0.id', $value->public_id)
        ->assertJsonCount(0, 'data.1.values');
});

it('returns paginated admin taxonomy records with an allowlisted sort', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(catalogStaffToken('support'));
    Brand::factory()->create(['name' => 'Alpha']);
    Brand::factory()->create(['name' => 'Zebra']);

    $this->getJson('/api/v1/admin/brands?sort_by=name&sort_direction=desc&per_page=1')
        ->assertOk()->assertJsonPath('data.0.name', 'Zebra')->assertJsonPath('meta.pagination.total', 2);

    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/brands?sort_by=created_by&per_page=101')
        ->assertUnprocessable()->assertJsonValidationErrors(['sort_by', 'per_page'], 'errors.fields');
});
