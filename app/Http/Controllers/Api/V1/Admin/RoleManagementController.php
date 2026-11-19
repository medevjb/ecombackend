<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RoleResource;
use App\Services\Auth\StaffAccess;
use App\Traits\ApiResponse;
use HasinHayder\Tyro\Models\Privilege;
use HasinHayder\Tyro\Models\Role;
use HasinHayder\Tyro\Support\TyroAudit;
use HasinHayder\Tyro\Support\TyroCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleManagementController extends Controller
{
    use ApiResponse;

    public function permissions(Request $request): JsonResponse
    {
        return $this->apiSuccess($request, config('staff-access.permissions'), 'Permissions retrieved.');
    }

    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()->whereNotIn('slug', ['*', 'customer', 'user'])->with('privileges')->orderBy('slug')->get();

        return $this->apiSuccess($request, RoleResource::collection($roles), 'Roles retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', 'unique:roles,slug'],
        ]);
        $role = Role::query()->create($data);

        return $this->apiSuccess($request, new RoleResource($role->load('privileges')), 'Role created.', 201);
    }

    public function show(Request $request, string $roleSlug): JsonResponse
    {
        return $this->apiSuccess($request, new RoleResource($this->role($roleSlug)->load('privileges')), 'Role retrieved.');
    }

    public function update(Request $request, string $roleSlug): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $role = $this->role($roleSlug);
        $this->requireMutable($role);
        $role->update($data);

        return $this->apiSuccess($request, new RoleResource($role->load('privileges')), 'Role updated.');
    }

    public function destroy(string $roleSlug): Response
    {
        DB::transaction(function () use ($roleSlug): void {
            $role = $this->role($roleSlug, lock: true);
            $this->requireMutable($role);
            abort_if(array_key_exists($roleSlug, config('staff-access.roles')), 409, 'Default staff roles cannot be deleted.');
            abort_if($role->users()->exists(), 409, 'Remove this role from its users before deleting it.');
            $role->delete();
        }, 3);

        return response()->noContent();
    }

    public function replacePermissions(Request $request, string $roleSlug, StaffAccess $access): JsonResponse
    {
        $allowed = array_diff(config('staff-access.permissions'), config('staff-access.super_admin_only'));
        $data = $request->validate([
            'permissions' => ['present', 'array', 'max:100'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($allowed), 'exists:privileges,slug'],
        ]);

        $role = DB::transaction(function () use ($access, $roleSlug, $data): Role {
            $role = $this->role($roleSlug, lock: true);
            $this->requireMutable($role);
            $before = $role->privileges()->pluck('slug')->all();
            $ids = Privilege::query()->whereIn('slug', $data['permissions'])->pluck('id');
            $role->privileges()->sync($ids);
            TyroCache::forgetUsersByRole($role);
            TyroAudit::log('role.permissions.replaced', $role, ['permissions' => $before], $data, $access->requestContext());

            return $role->load('privileges');
        }, 3);

        return $this->apiSuccess($request, new RoleResource($role), 'Role permissions updated.');
    }

    private function role(string $slug, bool $lock = false): Role
    {
        $query = Role::query()->where('slug', $slug)->whereNotIn('slug', ['*', 'customer', 'user']);

        return $lock ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
    }

    private function requireMutable(Role $role): void
    {
        abort_if($role->getAttribute('slug') === 'super-admin', 409, 'The Super Admin role is protected.');
    }
}
