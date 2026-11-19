<?php

use App\Models\StaffUser;
use App\Models\User;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Support\Facades\Auth;

it('issues a bearer token and returns the account roles', function (string $slug, int $adminStatus) {
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
    $user->assignRole($role);

    if ($slug !== 'user') {
        StaffUser::factory()->for($user, 'account')->create();
    }

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.roles', [$slug])
        ->assertJsonMissingPath('data.user.password')
        ->json('data.access_token');

    $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);

    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.roles', [$slug]);

    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/roles')->assertStatus($adminStatus);
})->with([
    'super admin' => ['super-admin', 200],
    'admin' => ['admin', 403],
    'regular user' => ['user', 401],
]);

it('rejects invalid credentials with 401 and throttles repeated attempts with 429', function () {
    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects missing credentials with JSON even without an accept header', function () {
    $this->post('/api/v1/auth/login')->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password'], 'errors.fields');
});

it('rejects suspended users and their previously issued tokens', function () {
    $user = User::factory()->create();
    $token = $user->createToken('client')->plainTextToken;
    $user->suspend('Account review');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(423)->assertJsonMissingPath('data.access_token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('revokes only the bearer token used to log out', function () {
    $user = User::factory()->create();
    $firstToken = $user->createToken('phone');
    $otherToken = $user->createToken('desktop');

    $this->withToken($firstToken->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertModelMissing($firstToken->accessToken);
    $this->assertModelExists($otherToken->accessToken);

    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects an existing management token after its super admin role is removed', function () {
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
    $user->assignRole($role);
    $staff = StaffUser::factory()->for($user, 'account')->create();
    $token = $staff->createToken('management', ['super-admin'])->plainTextToken;
    $user->removeRole($role);

    $this->withToken($token)->getJson('/api/v1/admin/roles')->assertForbidden();
});

it('exposes only the versioned login and requires a token for private routes', function () {
    $this->postJson('/api/login')->assertNotFound();
    $this->postJson('/api/users')->assertNotFound();
    $this->get('/api/v1/auth/me')->assertUnauthorized();
    $this->get('/api/v1/admin/roles')->assertUnauthorized();
});

it('rejects a token after its advertised expiration time', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'phone',
    ])->assertOk()->assertJsonPath('data.expires_at', now()->addHours(24)->toISOString());

    $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'phone']);

    Auth::forgetGuards();
    $this->travel(25)->hours();
    $this->withToken($login->json('data.access_token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
});
