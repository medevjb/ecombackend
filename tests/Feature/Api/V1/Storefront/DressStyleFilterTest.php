<?php

use App\Enums\CatalogStatus;
use App\Models\Category;

it('combines dress style with the selected product category', function () {
    [, , $product] = checkoutFixture();
    [, , $other] = checkoutFixture();
    $category = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'tees']);
    $styles = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'dress-styles']);
    $formal = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'formal', 'parent_id' => $styles->id]);
    $product->categories()->attach([$category->id, $formal->id]);
    $other->categories()->attach($formal);

    $this->getJson('/api/v1/products?category=tees&style=formal')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->public_id);
    $this->getJson('/api/v1/products?style=missing')->assertOk()->assertJsonCount(0, 'data');
});
