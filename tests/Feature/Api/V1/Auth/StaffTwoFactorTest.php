<?php

use App\Models\StaffUser;
use App\Services\Auth\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function staffCode(StaffUser $staff): string
{
    $encrypted = DB::table('two_factor_credentials')->where('staff_user_id', $staff->id)->value('secret');
    $secret = base64_decode(Crypt::decryptString($encrypted), true);

    return app(Totp::class)->code($secret, intdiv(now()->getTimestamp(), 30));
}

it('requires a fresh second factor before issuing a staff token and rejects replay', function () {
    $this->freezeTime();
    $staff = StaffUser::factory()->create();
    $initial = $staff->createToken('initial');
    $headers = ['Authorization' => 'Bearer '.$initial->plainTextToken];

    $this->postJson('/api/v1/admin/auth/2fa/setup', ['current_password' => 'wrong'], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/admin/auth/2fa/setup', ['current_password' => 'password'], $headers)->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'provisioning_uri', 'expires_at']]);

    $enrollmentCode = staffCode($staff);
    $recoveryCodes = $this->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => $enrollmentCode], $headers)
        ->assertOk()->assertJsonCount(8, 'data.recovery_codes')->json('data.recovery_codes');

    $this->assertModelMissing($initial->accessToken);
    $this->assertDatabaseHas('tyro_audit_logs', ['event' => 'staff.two-factor.enabled', 'user_id' => $staff->id]);
    expect(DB::table('two_factor_credentials')->where('staff_user_id', $staff->id)->value('recovery_codes'))
        ->not->toContain($recoveryCodes[0]);

    Auth::forgetGuards();
    $credentials = ['email' => $staff->account->email, 'password' => 'password'];
    $firstChallenge = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonMissingPath('data.access_token')->json('data.challenge_token');

    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $firstChallenge, 'code' => $enrollmentCode])
        ->assertUnprocessable()->assertJsonValidationErrors(['code'], 'errors.fields');

    $this->travel(30)->seconds();
    $freshCode = staffCode($staff);
    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $firstChallenge, 'code' => $freshCode])
        ->assertOk()->assertJsonPath('data.dashboard', 'staff')->assertJsonStructure(['data' => ['access_token']]);

    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $firstChallenge, 'code' => $freshCode])
        ->assertUnauthorized();

    Auth::forgetGuards();
    $next = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()->json('data.challenge_token');
    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $next, 'code' => $recoveryCodes[0]])->assertOk();

    Auth::forgetGuards();
    $next = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()->json('data.challenge_token');
    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $next, 'code' => $recoveryCodes[0]])
        ->assertUnprocessable();
});

it('replaces recovery codes and disables TOTP only after password and fresh code verification', function () {
    $this->freezeTime();
    $staff = StaffUser::factory()->create();
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('initial')->plainTextToken];

    $this->postJson('/api/v1/admin/auth/2fa/setup', ['current_password' => 'password'], $headers)->assertOk();
    $old = $this->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => staffCode($staff)], $headers)
        ->assertOk()->json('data.recovery_codes');

    Auth::forgetGuards();
    $this->travel(30)->seconds();
    $credentials = ['email' => $staff->account->email, 'password' => 'password'];
    $challenge = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()->json('data.challenge_token');
    $grant = $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => staffCode($staff)])
        ->assertOk()->json('data.access_token');

    $this->travel(30)->seconds();
    $headers = ['Authorization' => 'Bearer '.$grant];
    $replacement = $this->postJson('/api/v1/admin/auth/2fa/recovery', [
        'current_password' => 'password',
        'code' => staffCode($staff),
    ], $headers)->assertOk()->assertJsonCount(8, 'data.recovery_codes')->json('data.recovery_codes');
    expect($replacement)->not->toBe($old);

    $this->postJson('/api/v1/admin/auth/2fa/disable', [
        'current_password' => 'password',
        'code' => staffCode($staff),
    ], $headers)->assertUnprocessable();

    $this->travel(30)->seconds();
    $this->postJson('/api/v1/admin/auth/2fa/disable', [
        'current_password' => 'password',
        'code' => staffCode($staff),
    ], $headers)->assertNoContent();

    $this->assertDatabaseMissing('two_factor_credentials', ['staff_user_id' => $staff->id]);
    Auth::forgetGuards();
    $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
});

it('expires challenges and limits invalid codes across replacement challenges', function () {
    $this->freezeTime();
    $staff = StaffUser::factory()->create();
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('initial')->plainTextToken];
    $credentials = ['email' => $staff->account->email, 'password' => 'password'];

    $this->postJson('/api/v1/admin/auth/2fa/setup', ['current_password' => 'password'], $headers)->assertOk();
    $this->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => staffCode($staff)], $headers)->assertOk();

    Auth::forgetGuards();
    $expired = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()->json('data.challenge_token');
    $this->travel(5)->minutes();
    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $expired, 'code' => str_repeat('0', 32)])
        ->assertUnauthorized();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        Auth::forgetGuards();
        $challenge = $this->postJson('/api/v1/auth/login', $credentials)->assertAccepted()->json('data.challenge_token');
        $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => str_repeat('0', 32)])
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/admin/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => str_repeat('0', 32)])
        ->assertTooManyRequests()->assertHeader('Retry-After');
});
