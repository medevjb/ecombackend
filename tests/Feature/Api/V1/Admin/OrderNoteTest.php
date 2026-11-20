<?php

use App\Models\Order;
use App\Models\Payment;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;

it('stores internal order notes for permitted staff and keeps them out of customer orders', function () {
    $this->seed(StaffAccessSeeder::class);
    $order = Order::factory()->create();
    Payment::factory()->for($order)->create();
    $notes = '/api/v1/admin/orders/'.$order->public_id.'/notes';

    $this->withToken(catalogStaffToken('product-manager'))->getJson($notes)->assertForbidden();
    $this->postJson($notes, ['body' => 'Denied note'])->assertForbidden();
    $this->assertDatabaseCount('order_notes', 0);

    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('support'))->postJson($notes, ['body' => 'Customer requested a callback.'])
        ->assertCreated()->assertJsonPath('data.body', 'Customer requested a callback.');
    $this->getJson($notes)->assertOk()->assertJsonPath('data.0.body', 'Customer requested a callback.')
        ->assertJsonPath('meta.pagination.total', 1);
    $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'body' => 'Customer requested a callback.']);

    Auth::forgetGuards();
    $this->withToken($order->user->createToken('browser')->plainTextToken)->getJson($notes)->assertUnauthorized();
    $this->getJson('/api/v1/orders/'.$order->public_id)->assertOk()
        ->assertJsonMissingPath('data.notes')->assertJsonMissing(['body' => 'Customer requested a callback.']);
});
