<?php

namespace App\Services\Auth;

use App\Models\User;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class CustomerAccounts
{
    public function register(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        try {
            return DB::transaction(function () use ($name, $email, $password): User {
                $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->assignRole(Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']));

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::query()->where('email', $email)->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }
    }
}
