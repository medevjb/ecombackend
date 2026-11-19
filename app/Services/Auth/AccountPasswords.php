<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\QueuedPasswordReset;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AccountPasswords
{
    public function sendResetLink(string $email, bool $staff = false): void
    {
        Password::broker('users')->sendResetLink(
            ['email' => $email, $this->activeAccounts($staff)],
            fn (User $user, string $token) => $user->notify(new QueuedPasswordReset($token)),
        );
    }

    public function resetPassword(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password, bool $staff = false): bool
    {
        return DB::transaction(function () use ($email, $token, $password, $staff): bool {
            $active = $this->activeAccounts($staff);

            $result = Password::broker('users')->reset([
                'email' => $email,
                'token' => $token,
                'password' => $password,
                function (Builder $query) use ($active): void {
                    $active($query);
                    $query->lockForUpdate();
                },
            ], function (User $user, string $newPassword): void {
                $this->replacePassword($user, $newPassword);
            });

            return $result === Password::PASSWORD_RESET;
        }, 3);
    }

    public function changePassword(User $user, #[SensitiveParameter] string $current, #[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($user, $current, $password): void {
            $lockedUser = User::query()->lockForUpdate()->whereKey($user->id)->firstOrFail();

            if (! Hash::check($current, $lockedUser->password)) {
                throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
            }

            $this->replacePassword($lockedUser, $password);
            Password::broker('users')->deleteToken($lockedUser);
        }, 3);
    }

    private function activeAccounts(bool $staff): Closure
    {
        return function (Builder $query) use ($staff): void {
            $query->whereNull('suspended_at');

            if ($staff) {
                $query->whereHas('staffIdentity');
            } else {
                $query->scopes('customers');
            }
        };
    }

    private function replacePassword(User $user, #[SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => null])->save();
        $user->tokens()->delete();
        $user->staffIdentity?->tokens()->delete();
        DB::table('staff_login_challenges')->where('staff_user_id', $user->id)->delete();
    }
}
