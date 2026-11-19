<?php

use App\Models\StaffUser;
use App\Models\User;
use App\Notifications\QueuedPasswordReset;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

it('issues a staff bearer from shared login and rejects it on customer routes', function () {
    $staff = StaffUser::factory()->create();
    $staff->account->assignRole(Role::query()->firstOrCreate(['slug' => 'support'], ['name' => 'Support']));

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $staff->account->email,
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.identity_type', 'staff')
        ->assertJsonPath('data.dashboard', 'staff')
        ->assertJsonPath('data.user.roles', ['support'])
        ->json('data.access_token');

    $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => StaffUser::class, 'tokenable_id' => $staff->id]);
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/me', ['name' => 'Updated Support'])->assertOk()
        ->assertJsonPath('data.name', 'Updated Support');
});

it('issues a customer audience and rejects customer tokens on staff routes', function () {
    $customer = User::factory()->create();

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk()->assertJsonPath('data.dashboard', 'customer')->json('data.access_token');

    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
});

it('sends staff recovery only to staff and revokes the staff token after reset', function () {
    Notification::fake();
    $staff = StaffUser::factory()->create();
    $customer = User::factory()->create();
    $oldToken = $staff->createToken('old');

    $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $customer->email])->assertAccepted();
    $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $staff->account->email])->assertAccepted();

    Notification::assertNotSentTo($customer, QueuedPasswordReset::class);
    Notification::assertSentTo($staff->account, QueuedPasswordReset::class);
    $reset = Notification::sent($staff->account, QueuedPasswordReset::class)->sole();

    $request = [
        'email' => $staff->account->email,
        'token' => $reset->token,
        'password' => 'ChangedPass!123',
        'password_confirmation' => 'ChangedPass!123',
    ];
    $this->postJson('/api/v1/auth/reset-password', $request)->assertUnprocessable();
    $this->postJson('/api/v1/admin/auth/reset-password', $request)->assertNoContent();
    $this->assertModelMissing($oldToken->accessToken);
});

it('revokes staff tokens when the shared account is suspended', function () {
    $staff = StaffUser::factory()->create();
    $token = $staff->createToken('old');

    $staff->account->suspend('Account review');
    $staff->account->unsuspend();

    $this->assertModelMissing($token->accessToken);
});
