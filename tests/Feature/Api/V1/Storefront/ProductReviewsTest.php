<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

it('requires a purchase and updates public ratings only for approved reviews', function () {
    $this->seed(StaffAccessSeeder::class);
    [$buyer, , $product, $stock] = checkoutFixture();
    $token = $buyer->createToken('customer')->plainTextToken;
    $admin = catalogStaffToken('admin');
    $url = '/api/v1/products/'.$product->slug;
    $this->withToken($token)->postJson($url.'/reviews', ['rating' => 4])->assertForbidden()
        ->assertJsonPath('errors.code', 'REVIEW_PURCHASE_REQUIRED');

    $order = Order::factory()->create(['user_id' => $buyer->id, 'status' => OrderStatus::Paid, 'paid_at' => now()]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Succeeded, 'captured_amount' => 4200, 'captured_at' => now()]);
    $variant = $stock->variant;
    DB::table('order_items')->insert([
        'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'product_public_id' => $product->public_id, 'variant_public_id' => $variant->public_id, 'product_name' => $product->name,
        'sku' => $variant->sku, 'options' => '[]', 'quantity' => 1, 'unit_amount' => 4200, 'line_amount' => 4200,
    ]);

    Auth::forgetGuards();
    $id = $this->postJson($url.'/reviews', ['rating' => 4, 'title' => 'Good fit'])->assertCreated()
        ->assertJsonPath('data.verified_purchase', true)->assertJsonPath('data.status', 'pending')->json('data.id');
    Auth::forgetGuards();
    $this->postJson($url.'/reviews', ['rating' => 3])->assertConflict()->assertJsonPath('errors.code', 'REVIEW_EXISTS');
    $this->getJson($url.'/reviews')->assertOk()->assertJsonCount(0, 'data');

    Auth::forgetGuards();
    $this->withToken($admin)->postJson('/api/v1/admin/reviews/'.$id.'/moderate', ['status' => 'approved', 'reason' => 'Verified'])
        ->assertOk()->assertJsonPath('data.changed', 1);
    $this->getJson($url)->assertOk()->assertJsonPath('data.rating_count', 1)->assertJsonPath('data.rating_average', 4);
    $this->getJson($url.'/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.customer_id');

    Auth::forgetGuards();
    $other = User::factory()->create()->createToken('other')->plainTextToken;
    $ownReview = '/api/v1/me/reviews/'.$id;
    $this->withToken($other)->patchJson($ownReview, ['rating' => 1])->assertNotFound();
    Auth::forgetGuards();
    $this->withToken($token)->patchJson($ownReview, ['rating' => 5])->assertOk()->assertJsonPath('data.status', 'pending');
    $this->getJson($url)->assertOk()->assertJsonPath('data.rating_count', 0)->assertJsonPath('data.rating_average', null);

    Auth::forgetGuards();
    $this->withToken($admin)->postJson('/api/v1/admin/reviews/bulk-actions', ['ids' => [$id], 'status' => 'approved', 'reason' => 'Checked'])
        ->assertOk()->assertJsonPath('data.changed', 1);
    Auth::forgetGuards();
    $this->withToken($token)->deleteJson($ownReview)->assertNoContent();
    $this->getJson($url)->assertOk()->assertJsonPath('data.rating_count', 0);
});

it('labels optional unverified submissions and rejects injected moderation state', function () {
    config(['reviews.require_verified_purchase' => false]);
    [$customer, , $product] = checkoutFixture();
    $url = '/api/v1/products/'.$product->slug.'/reviews';
    $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson($url, ['rating' => 5, 'verified_purchase' => true, 'status' => 'approved'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status', 'verified_purchase'], 'errors.fields');
    Auth::forgetGuards();
    $this->postJson($url, ['rating' => 5])->assertCreated()->assertJsonPath('data.verified_purchase', false);
});
