<?php

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('keeps address edits scoped to their owner and supports one default', function () {
    $user = User::factory()->create();
    $other = CustomerAddress::factory()->create();
    $this->withToken($user->createToken('customer')->plainTextToken);
    $base = '/api/v1/me/addresses';
    $input = ['recipient' => 'Sample Customer', 'line1' => '1 Main Street', 'city' => 'Portland',
        'country' => 'us', 'postal_code' => '97035', 'is_default' => true];

    $first = $this->postJson($base, $input + ['user_id' => 999])->assertCreated()
        ->assertJsonPath('data.country', 'US')->assertJsonMissingPath('data.user_id')->json('data.id');
    Auth::forgetGuards();
    $second = $this->postJson($base, array_replace($input, ['line1' => '2 Main Street']))
        ->assertCreated()->json('data.id');

    $this->assertDatabaseHas('customer_addresses', ['public_id' => $first, 'user_id' => $user->id, 'is_default' => false]);
    $this->assertDatabaseHas('customer_addresses', ['public_id' => $second, 'is_default' => true]);

    Auth::forgetGuards();
    $this->patchJson($base.'/'.$other->public_id, ['city' => 'Denied'])->assertNotFound();
    Auth::forgetGuards();
    $this->deleteJson($base.'/'.$other->public_id)->assertNotFound();
    Auth::forgetGuards();
    $this->getJson($base)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second);
});
