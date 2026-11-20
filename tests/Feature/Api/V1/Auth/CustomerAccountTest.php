<?php

use App\Models\User;
use App\Notifications\CustomerEmailVerificationCode;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

it('registers only a customer and supports shared login and profile updates', function () {
    Notification::fake();
    $registration = $this->postJson('/api/v1/auth/register', [
        'name' => 'Example Customer',
        'email' => ' CUSTOMER@EXAMPLE.TEST ',
        'password' => 'SecurePass!234',
        'password_confirmation' => 'SecurePass!234',
        'roles' => ['super-admin'],
    ])->assertCreated()
        ->assertJsonPath('data.email', 'customer@example.test')
        ->assertJsonPath('data.user.roles', ['customer'])
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonMissingPath('data.user.password');

    expect($registration->json('data.user.id'))->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/');

    $credentials = ['email' => 'CUSTOMER@EXAMPLE.TEST', 'password' => 'SecurePass!234'];
    $challenge = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()
        ->assertJsonPath('data.email_verification_required', true)->assertJsonMissingPath('data.access_token');

    $customer = User::query()->where('public_id', $registration->json('data.user.id'))->firstOrFail();
    $code = Notification::sent($customer, CustomerEmailVerificationCode::class)->last()->code;
    $verify = ['verification_token' => $challenge->json('data.verification_token'), 'code' => $code];
    $this->postJson('/api/v1/auth/email/verify-code', $verify)
        ->assertOk()->assertJsonPath('data.user.email_verified', true)->assertJsonStructure(['data' => ['access_token']]);
    $this->postJson('/api/v1/auth/email/verify-code', $verify)
        ->assertUnprocessable()->assertJsonValidationErrors('code', 'errors.fields');

    $login = $this->postJson('/api/v1/auth/login', $credentials)->assertOk();

    Auth::forgetGuards();

    $this->withToken($login->json('data.access_token'))->patchJson('/api/v1/me', [
        'name' => 'Updated Customer',
    ])->assertOk()->assertJsonPath('data.name', 'Updated Customer');

    $this->assertDatabaseHas('users', ['public_id' => $registration->json('data.user.id'), 'name' => 'Updated Customer']);
});

it('rejects weak passwords and case-insensitive duplicates with 422', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Example Customer',
        'email' => 'TAKEN@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password'], 'errors.fields');
});

it('keeps recovery responses uniform and uses a reset token only once', function () {
    Notification::fake();
    $user = User::factory()->create();
    $existingToken = $user->createToken('old');

    $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertAccepted();
    expect($existing->json('message'))->toBe($unknown->json('message'));

    expect($existing->json('data.verification_token'))->toHaveLength(64)
        ->and($unknown->json('data.verification_token'))->toHaveLength(64);

    $notification = Notification::sent($user, CustomerEmailVerificationCode::class)->sole();
    expect($notification->purpose)->toBe('password_reset');
    $challenge = ['verification_token' => $existing->json('data.verification_token'), 'code' => $notification->code];
    $token = $this->postJson('/api/v1/auth/forgot-password/verify-code', $challenge)
        ->assertOk()->assertJsonPath('data.email', $user->email)->json('data.reset_token');
    $this->postJson('/api/v1/auth/forgot-password/verify-code', $challenge)
        ->assertUnprocessable()->assertJsonValidationErrors('code', 'errors.fields');

    $input = [
        'email' => $user->email,
        'token' => $token,
        'password' => 'NewSecurePass!789',
        'password_confirmation' => 'NewSecurePass!789',
    ];
    $this->postJson('/api/v1/auth/reset-password', $input)->assertNoContent();
    $this->assertModelMissing($existingToken->accessToken);
    expect(Hash::check($input['password'], $user->refresh()->password))->toBeTrue();

    $this->postJson('/api/v1/auth/reset-password', $input)->assertUnprocessable()
        ->assertJsonPath('errors.code', 'INVALID_RESET_TOKEN');
});

it('checks the current password and revokes existing tokens when it changes', function () {
    $user = User::factory()->create();
    $token = $user->createToken('browser');

    $input = ['password' => 'NewSecurePass!789', 'password_confirmation' => 'NewSecurePass!789'];
    $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/change-password', $input + [
        'current_password' => 'incorrect',
    ])->assertUnprocessable()->assertJsonValidationErrors(['current_password'], 'errors.fields');

    Auth::forgetGuards();

    $this->postJson('/api/v1/auth/change-password', $input + ['current_password' => 'password'])->assertNoContent();
    $this->assertModelMissing($token->accessToken);
});

it('blocks staff access to customer profiles and customer recovery emails', function () {
    Notification::fake();
    $staff = User::factory()->create();
    $staff->assignRole(Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']));

    $this->withToken($staff->createToken('staff', ['admin'])->plainTextToken)->getJson('/api/v1/me')->assertForbidden();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $staff->email])->assertAccepted();
    Notification::assertNothingSent();
});

it('continues to accept valid legacy verification links', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $token = $user->createToken('browser');

    $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();
    Notification::assertSentTo($user, CustomerEmailVerificationCode::class);

    $parameters = ['id' => $user->public_id, 'hash' => sha1($user->email)];
    $this->getJson(route('api.v1.auth.verification.verify', $parameters, false))->assertForbidden();
    $this->getJson(URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addMinutes(10), $parameters, false))
        ->assertNoContent();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects expired password reset tokens', function () {
    $this->freezeTime();
    Notification::fake();
    $user = User::factory()->create();

    $challenge = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
    $notification = Notification::sent($user, CustomerEmailVerificationCode::class)->sole();
    $token = $this->postJson('/api/v1/auth/forgot-password/verify-code', [
        'verification_token' => $challenge->json('data.verification_token'),
        'code' => $notification->code,
    ])->assertOk()->json('data.reset_token');
    $this->travel(16)->minutes();
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'AnotherPass!123',
        'password_confirmation' => 'AnotherPass!123',
    ])->assertUnprocessable()->assertJsonPath('errors.code', 'INVALID_RESET_TOKEN');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});
