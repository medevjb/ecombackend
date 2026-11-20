<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\StaffUser;
use App\Models\User;
use Database\Seeders\StaffAccessSeeder;
use Illuminate\Support\Facades\Auth;

it('scopes staff searches and customer order history and deactivates without deleting orders', function () {
    $this->seed(StaffAccessSeeder::class);
    $customer = User::factory()->create(['name' => 'ScopeFixture Customer']);
    $staff = StaffUser::factory()->create();
    $staff->account->update(['name' => 'ScopeFixture Staff']);
    $own = Order::factory()->create(['user_id' => $customer->id]);
    Payment::factory()->for($own)->create();
    Order::factory()->create(['user_id' => User::factory()->create(['name' => 'Other Account'])->id]);
    $customer->createToken('customer');
    $base = '/api/v1/admin/customers';
    $url = $base.'/'.$customer->public_id;
    $this->withToken(catalogStaffToken('support'))->getJson($base.'?search=ScopeFixture')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->public_id)
        ->assertJsonMissingPath('data.0.password');
    Auth::forgetGuards();
    $this->getJson($base.'/'.$staff->account->public_id)->assertNotFound();
    Auth::forgetGuards();
    $this->getJson($url.'/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->public_id);
    Auth::forgetGuards();
    $this->patchJson($url.'/status', ['active' => false, 'reason' => 'Customer requested a pause'])->assertForbidden();
    Auth::forgetGuards();
    $this->withToken(catalogStaffToken('admin'))->patchJson($url.'/status', ['active' => false, 'reason' => 'Customer requested a pause'])
        ->assertOk()->assertJsonPath('data.active', false);
    expect($customer->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $own->id]);
});
