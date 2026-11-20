<?php

use App\Enums\ProductStatus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('persists visible wishlist products once and keeps them scoped to the customer', function () {
    [$customer, , $product] = checkoutFixture();
    [, , $otherProduct] = checkoutFixture();
    $otherCustomer = User::factory()->create();
    $base = '/api/v1/me/wishlist';
    $item = $base.'/'.$product->public_id;

    $this->getJson($base)->assertUnauthorized();
    $token = $customer->createToken('browser')->plainTextToken;
    $this->withToken($token)->putJson($item)->assertNoContent();
    $this->putJson($item)->assertNoContent();
    $this->putJson($base.'/'.$otherProduct->public_id)->assertNoContent();
    $this->getJson($base.'?per_page=1&page=2')->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)->assertJsonCount(1, 'data');
    $this->getJson($item)->assertOk()->assertJsonPath('data.saved', true);

    Auth::forgetGuards();
    $this->withToken($otherCustomer->createToken('browser')->plainTextToken)->deleteJson($item)->assertNoContent();
    $this->getJson($item)->assertOk()->assertJsonPath('data.saved', false);
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');

    Auth::forgetGuards();
    $this->withToken($token)->getJson($item)->assertOk()->assertJsonPath('data.saved', true);
    $product->forceFill(['status' => ProductStatus::Inactive])->save();
    $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otherProduct->public_id);
    $this->deleteJson($item)->assertNoContent();
    $this->putJson($item)->assertNotFound();
    $this->assertDatabaseMissing('customer_wishlists', ['user_id' => $customer->id, 'product_id' => $product->id]);
});
