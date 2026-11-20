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

it('matches any selected clothing category and dress style together', function () {
    [, , $tee] = checkoutFixture();
    [, , $hoodie] = checkoutFixture();
    [, , $unmatchedStyle] = checkoutFixture();
    $tees = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'tees']);
    $hoodies = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'hoodies']);
    $dressStyles = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'dress-styles']);
    $formal = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'formal', 'parent_id' => $dressStyles->id]);
    $casual = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'casual', 'parent_id' => $dressStyles->id]);
    $sporty = Category::factory()->create(['status' => CatalogStatus::Active, 'slug' => 'sporty', 'parent_id' => $dressStyles->id]);
    $tee->categories()->attach([$tees->id, $formal->id]);
    $hoodie->categories()->attach([$hoodies->id, $casual->id]);
    $unmatchedStyle->categories()->attach([$tees->id, $sporty->id]);

    $this->getJson('/api/v1/products?categories%5B%5D=tees&categories%5B%5D=hoodies&styles%5B%5D=formal&styles%5B%5D=casual')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $tee->public_id])
        ->assertJsonFragment(['id' => $hoodie->public_id]);
});
