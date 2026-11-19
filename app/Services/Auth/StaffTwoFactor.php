<?php

namespace App\Services\Auth;

use App\Models\StaffUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class StaffTwoFactor
{
    public function __construct(private readonly Totp $totp, private readonly IssueAccessToken $tokens) {}

    /** @return array<string, mixed> */
    public function login(User $account, string $device, Request $request): array
    {
        return DB::transaction(function () use ($account, $device, $request): array {
            $locked = User::query()->lockForUpdate()->whereKey($account->id)->firstOrFail();
            abort_unless(hash_equals($locked->password, $account->password), 401);
            abort_if($locked->isSuspended(), 423);

            $staff = $locked->staffIdentity;

            if ($staff === null || ! DB::table('two_factor_credentials')->where('staff_user_id', $staff->id)->whereNotNull('confirmed_at')->exists()) {
                return $this->tokens->issue($locked, $device, $request);
            }

            $token = bin2hex(random_bytes(32));
            $expires = now()->addMinutes(5);

            DB::table('staff_login_challenges')->updateOrInsert(['staff_user_id' => $staff->id], [
                'token_hash' => hash('sha256', $token),
                'device_name' => $device,
                'expires_at' => $expires,
            ]);

            return ['two_factor_required' => true, 'challenge_token' => $token, 'expires_at' => $expires->toISOString()];
        }, 3);
    }

    /** @return array{secret: string, provisioning_uri: string, expires_at: string} */
    public function setup(StaffUser $staff, #[SensitiveParameter] string $password): array
    {
        return DB::transaction(function () use ($staff, $password): array {
            $account = $this->authorizedAccount($staff, $password);
            $credential = $this->credential($staff->id);
            abort_if($credential?->confirmed_at !== null, 409, 'Two-factor authentication is already enabled.');

            $secret = random_bytes(20);
            $expires = now()->addMinutes(10);

            DB::table('two_factor_credentials')->updateOrInsert(['staff_user_id' => $staff->id], [
                'secret' => Crypt::encryptString(base64_encode($secret)),
                'enrollment_expires_at' => $expires,
                'confirmed_at' => null,
                'last_used_step' => null,
                'recovery_codes' => null,
            ]);

            $encoded = $this->totp->encodeSecret($secret);
            $issuer = (string) config('app.name');

            return [
                'secret' => $encoded,
                'provisioning_uri' => 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account->email).'?'.http_build_query([
                    'secret' => $encoded,
                    'issuer' => $issuer,
                    'algorithm' => 'SHA1',
                    'digits' => 6,
                    'period' => 30,
                ], encoding_type: PHP_QUERY_RFC3986),
                'expires_at' => $expires->toISOString(),
            ];
        }, 3);
    }

    /** @return list<string> */
    public function confirm(StaffUser $staff, #[SensitiveParameter] string $code): array
    {
        return DB::transaction(function () use ($staff, $code): array {
            $account = $this->lockedAccount($staff);
            $credential = $this->credential($staff->id);
            abort_if($credential === null || $credential->confirmed_at !== null
                || CarbonImmutable::parse($credential->enrollment_expires_at)->lte(now()), 409, 'No active two-factor enrollment.');

            $step = $this->verifyTotp($credential, $code);
            $codes = $this->replaceRecoveryCodes($staff->id, [
                'confirmed_at' => now(),
                'last_used_step' => $step,
            ]);
            $this->revokeStaffTokens($staff);
            TyroAudit::log('staff.two-factor.enabled', $account);

            return $codes;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function challenge(#[SensitiveParameter] string $token, #[SensitiveParameter] string $code, Request $request): array
    {
        $hash = hash('sha256', $token);
        $initial = DB::table('staff_login_challenges')->where('token_hash', $hash)->first();
        abort_if($initial === null || CarbonImmutable::parse($initial->expires_at)->lte(now()), 401);

        $limitKey = 'staff-two-factor:'.$initial->staff_user_id;

        if (RateLimiter::tooManyAttempts($limitKey, 5) || RateLimiter::hit($limitKey, 300) > 5) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($limitKey));
        }

        return DB::transaction(function () use ($initial, $hash, $code, $request, $limitKey): array {
            $staff = StaffUser::query()->whereKey($initial->staff_user_id)->firstOrFail();
            $account = $this->lockedAccount($staff, requireCurrentToken: false);
            $challenge = DB::table('staff_login_challenges')->where('staff_user_id', $staff->id)->where('token_hash', $hash)->first();
            abort_if($challenge === null || CarbonImmutable::parse($challenge->expires_at)->lte(now()), 401);

            $credential = $this->credential($staff->id);
            abort_if($credential === null || $credential->confirmed_at === null, 401);

            if (strlen($code) === 6) {
                $changes = ['last_used_step' => $this->verifyTotp($credential, $code)];
            } else {
                $recovery = $this->recoveryHashes($credential);
                $index = array_search(hash('sha256', $code), $recovery, true);
                if ($index === false) {
                    $this->invalidCode();
                }
                unset($recovery[$index]);
                $changes = ['recovery_codes' => json_encode(array_values($recovery), JSON_THROW_ON_ERROR)];
                TyroAudit::log('staff.two-factor.recovery-used', $account);
            }

            DB::table('two_factor_credentials')->where('staff_user_id', $staff->id)->update($changes);
            DB::table('staff_login_challenges')->where('staff_user_id', $staff->id)->delete();

            $result = $this->tokens->issue($account, $challenge->device_name, $request);
            RateLimiter::clear($limitKey);

            return $result;
        }, 3);
    }

    public function disable(StaffUser $staff, #[SensitiveParameter] string $password, #[SensitiveParameter] string $code): void
    {
        DB::transaction(function () use ($staff, $password, $code): void {
            $account = $this->authorizedAccount($staff, $password);
            $credential = $this->credential($staff->id);
            abort_if($credential === null || $credential->confirmed_at === null, 409, 'Two-factor authentication is not enabled.');

            $this->verifyTotp($credential, $code);
            DB::table('two_factor_credentials')->where('staff_user_id', $staff->id)->delete();
            $this->revokeStaffTokens($staff);
            TyroAudit::log('staff.two-factor.disabled', $account);
        }, 3);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(StaffUser $staff, #[SensitiveParameter] string $password, #[SensitiveParameter] string $code): array
    {
        return DB::transaction(function () use ($staff, $password, $code): array {
            $account = $this->authorizedAccount($staff, $password);
            $credential = $this->credential($staff->id);
            abort_if($credential === null || $credential->confirmed_at === null, 409, 'Two-factor authentication is not enabled.');

            $codes = $this->replaceRecoveryCodes($staff->id, ['last_used_step' => $this->verifyTotp($credential, $code)]);
            TyroAudit::log('staff.two-factor.recovery-regenerated', $account);

            return $codes;
        }, 3);
    }

    private function lockedAccount(StaffUser $staff, bool $requireCurrentToken = true): User
    {
        $account = User::query()->lockForUpdate()->whereKey($staff->id)->firstOrFail();
        abort_if($account->isSuspended(), 401);
        abort_if($requireCurrentToken && ! $staff->tokens()->whereKey($staff->currentAccessToken()->getKey())->exists(), 401);

        return $account;
    }

    private function authorizedAccount(StaffUser $staff, #[SensitiveParameter] string $password): User
    {
        $account = $this->lockedAccount($staff);

        if (! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        return $account;
    }

    private function credential(int $staffId): ?stdClass
    {
        return DB::table('two_factor_credentials')->where('staff_user_id', $staffId)->first();
    }

    private function verifyTotp(stdClass $credential, #[SensitiveParameter] string $code): int
    {
        $secret = base64_decode(Crypt::decryptString($credential->secret), true);
        $step = $secret === false ? null : $this->totp->matchStep(
            $secret,
            $code,
            now()->getTimestamp(),
            $credential->last_used_step,
        );

        if ($step === null) {
            $this->invalidCode();
        }

        return $step;
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages(['code' => ['The verification code is invalid or has already been used.']]);
    }

    /** @return list<string> */
    private function recoveryHashes(stdClass $credential): array
    {
        $decoded = json_decode($credential->recovery_codes ?? '[]', true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param array<string, mixed> $changes
     * @return list<string>
     */
    private function replaceRecoveryCodes(int $staffId, array $changes): array
    {
        $codes = array_map(fn (): string => bin2hex(random_bytes(16)), range(1, 8));
        $changes['recovery_codes'] = json_encode(array_map(fn (string $code): string => hash('sha256', $code), $codes), JSON_THROW_ON_ERROR);
        DB::table('two_factor_credentials')->where('staff_user_id', $staffId)->update($changes);

        return $codes;
    }

    private function revokeStaffTokens(StaffUser $staff): void
    {
        $staff->tokens()->delete();
        DB::table('staff_login_challenges')->where('staff_user_id', $staff->id)->delete();
    }
}
