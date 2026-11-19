<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\StaffAccess;
use HasinHayder\Tyro\Models\Role;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('staff:bootstrap-super-admin {email} {--name=Super Admin}')]
#[Description('Provision the first Super Admin using an interactive password prompt')]
class BootstrapSuperAdmin extends Command
{
    public function handle(StaffAccess $access): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter a password.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $data = ['email' => $email, 'name' => $this->option('name'), 'password' => $this->secret('Password')];
        $validator = Validator::make($data, [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', fn ($attribute, $value, $fail) => strlen($value) > 72 ? $fail('The password must be at most 72 bytes.') : null],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        try {
            $created = DB::transaction(function () use ($access, $data): bool {
                $role = Role::query()->where('slug', 'super-admin')->lockForUpdate()->first();
                if ($role === null || User::query()->whereHas('roles', fn ($roles) => $roles->whereKey($role->getKey()))->exists()) {
                    return false;
                }
                $account = User::query()->create($data);
                $account->staffIdentity()->create();
                $access->replaceRoles($account, ['super-admin']);
                TyroAudit::log('staff.bootstrapped', $account, null, ['roles' => ['super-admin']]);

                return true;
            }, 5);
        } catch (UniqueConstraintViolationException) {
            $this->error('An account already exists for this email.');

            return self::FAILURE;
        }
        if (! $created) {
            $this->error('Bootstrap requires the seeded Super Admin role and no existing Super Admin.');

            return self::FAILURE;
        }
        $this->info('Super Admin created. Use the shared API login.');

        return self::SUCCESS;
    }
}
