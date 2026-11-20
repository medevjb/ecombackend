<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\CustomerEmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerEmailVerificationCodes
{
    public function send(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('customer_email_verification_codes')->upsert([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($code),
            'expires_at' => $now->copy()->addMinutes(10),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['user_id'], ['code_hash', 'expires_at', 'attempts', 'updated_at']);

        $user->notify(new CustomerEmailVerificationCode($code));
    }

    public function verify(User $user, string $code): void
    {
        $result = DB::transaction(function () use ($user, $code): string {
            $verification = DB::table('customer_email_verification_codes')
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($verification === null || now()->greaterThan($verification->expires_at)) {
                DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();

                return 'expired';
            }

            if ($verification->attempts >= 5) {
                DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();

                return 'expired';
            }

            if (! Hash::check($code, $verification->code_hash)) {
                $attempts = $verification->attempts + 1;
                DB::table('customer_email_verification_codes')
                    ->where('user_id', $user->getKey())
                    ->update(['attempts' => $attempts, 'updated_at' => now()]);

                if ($attempts >= 5) {
                    DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();
                }

                return 'invalid';
            }

            if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
                DB::afterCommit(fn () => event(new Verified($user)));
            }
            DB::table('customer_email_verification_codes')->where('user_id', $user->getKey())->delete();

            return 'verified';
        });

        if ($result !== 'verified') {
            throw ValidationException::withMessages([
                'code' => [$result === 'expired'
                    ? 'This verification code has expired. Request a new code to continue.'
                    : 'That verification code is incorrect. Check the code and try again.'],
            ]);
        }
    }
}
