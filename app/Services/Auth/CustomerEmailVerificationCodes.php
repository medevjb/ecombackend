<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\CustomerEmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerEmailVerificationCodes
{
    public const PURPOSE_REGISTRATION = 'registration';

    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public function issue(User $user, string $purpose, ?string $challengeToken = null): string
    {
        $challengeToken ??= Str::random(64);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('customer_email_verification_codes')->upsert([
            'user_id' => $user->getKey(),
            'challenge_hash' => hash('sha256', $challengeToken),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => $now->copy()->addMinutes(10),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['user_id'], ['challenge_hash', 'purpose', 'code_hash', 'expires_at', 'attempts', 'updated_at']);

        $user->notify(new CustomerEmailVerificationCode($code, $purpose));

        return $challengeToken;
    }

    public function issueForEmail(string $email, string $purpose): string
    {
        $challengeToken = Str::random(64);
        $user = User::query()->customers()->whereNull('suspended_at')->where('email', $email)->first();

        if ($user !== null) {
            $this->issue($user, $purpose, $challengeToken);
        }

        return $challengeToken;
    }

    public function resend(string $challengeToken): void
    {
        $verification = DB::table('customer_email_verification_codes')
            ->where('challenge_hash', hash('sha256', $challengeToken))
            ->first();

        if ($verification === null) {
            return;
        }

        $user = User::query()->customers()->whereNull('suspended_at')->whereKey($verification->user_id)->first();

        if ($user !== null) {
            $this->issue($user, $verification->purpose, $challengeToken);
        }
    }

    /** @param list<string> $allowedPurposes
     * @return array{user: User, purpose: string}
     */
    public function verify(string $challengeToken, string $code, array $allowedPurposes): array
    {
        $result = DB::transaction(function () use ($challengeToken, $code, $allowedPurposes): array {
            $verification = DB::table('customer_email_verification_codes')
                ->where('challenge_hash', hash('sha256', $challengeToken))
                ->lockForUpdate()
                ->first();

            if ($verification === null) {
                return ['error' => 'invalid', 'user' => null, 'purpose' => null];
            }

            if (! in_array($verification->purpose, $allowedPurposes, true)) {
                return ['error' => 'invalid', 'user' => null, 'purpose' => null];
            }

            $user = User::query()->customers()->whereNull('suspended_at')->lockForUpdate()->whereKey($verification->user_id)->first();

            if ($user === null) {
                DB::table('customer_email_verification_codes')->where('user_id', $verification->user_id)->delete();

                return ['error' => 'invalid', 'user' => null, 'purpose' => null];
            }

            if (now()->greaterThan($verification->expires_at) || $verification->attempts >= 5) {
                DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();

                return ['error' => 'expired', 'user' => null, 'purpose' => null];
            }

            if (! Hash::check($code, $verification->code_hash)) {
                $attempts = $verification->attempts + 1;
                DB::table('customer_email_verification_codes')
                    ->where('user_id', $user->getKey())
                    ->update(['attempts' => $attempts, 'updated_at' => now()]);

                if ($attempts >= 5) {
                    DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();
                }

                return ['error' => 'invalid', 'user' => null, 'purpose' => null];
            }

            if ($verification->purpose !== self::PURPOSE_PASSWORD_RESET
                && ! $user->hasVerifiedEmail()
                && $user->markEmailAsVerified()) {
                DB::afterCommit(fn () => event(new Verified($user)));
            }

            DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();

            return ['error' => null, 'user' => $user, 'purpose' => $verification->purpose];
        });

        if ($result['error'] !== null) {
            throw ValidationException::withMessages([
                'code' => [$result['error'] === 'expired'
                    ? 'This code has expired. Request a new code to continue.'
                    : 'That code is incorrect or expired. Check it and try again.'],
            ]);
        }

        return ['user' => $result['user'], 'purpose' => $result['purpose']];
    }
}
