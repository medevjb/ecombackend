<?php

namespace App\Services\Auth;

use App\Models\StaffUser;
use App\Models\User;
use HasinHayder\Tyro\Models\Role;
use HasinHayder\Tyro\Support\TyroAudit;
use HasinHayder\Tyro\Support\TyroCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StaffAccess
{
    public function allows(StaffUser $staff, string $permission): bool
    {
        return in_array($permission, $this->permissions($staff->account), true);
    }

    /** @return list<string> */
    public function permissions(User $account): array
    {
        $permissions = config('staff-access.permissions');
        $roles = $account->loadMissing('roles.privileges')->roles;

        if ($roles->contains('slug', 'super-admin')) {
            return $permissions;
        }

        $grants = $roles->whereInstanceOf(Role::class)->flatMap(fn (Role $role) => $role->privileges->pluck('slug'))->all();

        return array_values(array_intersect(array_diff($permissions, config('staff-access.super_admin_only')), $grants));
    }

    /** @param list<string> $slugs */
    public function replaceRoles(User $account, array $slugs): void
    {
        DB::transaction(function () use ($account, $slugs): void {
            $superAdmin = $this->lockSuperAdminRole();
            $locked = User::query()->whereKey($account->getKey())->whereHas('staffIdentity')->lockForUpdate()->firstOrFail();
            $previous = $locked->roles()->pluck('slug')->all();

            if (! in_array('super-admin', $slugs, true)) {
                $this->requireAnotherSuperAdmin($locked, $superAdmin);
            }

            $roles = Role::query()->whereIn('slug', $slugs)->lockForUpdate()->get();
            abort_unless($roles->count() === count($slugs), 422, 'A selected role is unavailable.');
            $locked->roles()->sync($roles->modelKeys());
            TyroCache::forgetUser($locked);
            TyroAudit::log('staff.roles.replaced', $locked, ['roles' => $previous], ['roles' => $slugs], $this->requestContext());
        }, 3);
    }

    public function setActive(User $account, bool $active): void
    {
        DB::transaction(function () use ($account, $active): void {
            $superAdmin = $this->lockSuperAdminRole();
            $locked = User::query()->whereKey($account->getKey())->whereHas('staffIdentity')->lockForUpdate()->firstOrFail();

            if ($active === ! $locked->isSuspended()) {
                return;
            }

            if ($active) {
                $locked->unsuspend();
            } else {
                $this->requireAnotherSuperAdmin($locked, $superAdmin);
                $locked->suspend('Staff account disabled');
            }

            TyroAudit::log('staff.status.updated', $locked, ['active' => ! $active], ['active' => $active], $this->requestContext());
        }, 3);
    }

    /** @return array{request_id: mixed} */
    public function requestContext(): array
    {
        return ['request_id' => request()->attributes->get('api_request_id')];
    }

    private function lockSuperAdminRole(): Role
    {
        return Role::query()->where('slug', 'super-admin')->lockForUpdate()->firstOrFail();
    }

    private function requireAnotherSuperAdmin(User $account, Role $role): void
    {
        if ($account->isSuspended() || ! $account->roles()->where('roles.id', $role->getKey())->exists()) {
            return;
        }

        $other = User::query()->whereKeyNot($account->getKey())
            ->whereNull('suspended_at')
            ->whereHas('staffIdentity')
            ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.id', $role->getKey()))
            ->lockForUpdate()
            ->first();

        abort_if($other === null, 409, 'At least one active Super Admin is required.');
    }
}
