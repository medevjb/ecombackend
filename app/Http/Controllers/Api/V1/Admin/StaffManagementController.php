<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StaffResource;
use App\Models\User;
use App\Services\Auth\StaffAccess;
use App\Traits\ApiResponse;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffManagementController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sort_by' => ['sometimes', Rule::in(['created_at', 'name', 'email'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ]);
        $query = User::query()->whereHas('staffIdentity')->with('roles.privileges');

        if (isset($filters['search'])) {
            $prefix = addcslashes($filters['search'], '\\%_').'%';
            $query->where(fn (Builder $scope) => $scope->whereRaw("name LIKE ? ESCAPE '!'", [$prefix])->orWhereRaw("email LIKE ? ESCAPE '!'", [$prefix]));
        }

        if (isset($filters['status'])) {
            $filters['status'] === 'active' ? $query->whereNull('suspended_at') : $query->whereNotNull('suspended_at');
        }

        $direction = $filters['sort_direction'] ?? 'asc';
        $paginator = $query->orderBy($filters['sort_by'] ?? 'name', $direction)->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, StaffResource::collection($paginator), 'Staff retrieved.', meta: [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, StaffAccess $access): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }

        $data = $request->validate($this->roleRules() + [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', 'regex:/\A[\s\S]{1,72}\z/', Password::defaults()],
        ]);

        $account = DB::transaction(function () use ($access, $data): User {
            $account = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $account->staffIdentity()->create();
            $access->replaceRoles($account, $data['roles']);
            TyroAudit::log('staff.created', $account, null, ['roles' => $data['roles']], $access->requestContext());

            return $account;
        }, 3);

        return $this->apiSuccess($request, new StaffResource($account), 'Staff account created.', 201);
    }

    public function show(Request $request, string $staff): JsonResponse
    {
        return $this->apiSuccess($request, new StaffResource($this->account($staff)), 'Staff account retrieved.');
    }

    public function update(Request $request, string $staff, StaffAccess $access): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);
        $account = $this->account($staff);

        DB::transaction(function () use ($account, $data, $access): void {
            if (array_key_exists('active', $data)) {
                $access->setActive($account, (bool) $data['active']);
            }

            if (isset($data['name']) && $account->name !== $data['name']) {
                $account->update(['name' => $data['name']]);
                TyroAudit::log('staff.profile.updated', $account, null, ['changed_fields' => ['name']], $access->requestContext());
            }
        }, 3);

        return $this->apiSuccess($request, new StaffResource($account->refresh()), 'Staff account updated.');
    }

    public function replaceRoles(Request $request, string $staff, StaffAccess $access): JsonResponse
    {
        $data = $request->validate($this->roleRules());
        $account = $this->account($staff);
        $access->replaceRoles($account, $data['roles']);

        return $this->apiSuccess($request, new StaffResource($account->refresh()), 'Staff roles updated.');
    }

    private function account(string $id): User
    {
        return User::query()->where('public_id', $id)->whereHas('staffIdentity')->with('roles.privileges')->firstOrFail();
    }

    /** @return array<string, list<mixed>> */
    private function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:25'],
            'roles.*' => ['required', 'string', 'distinct', Rule::notIn(['*', 'customer', 'user']), 'exists:roles,slug'],
        ];
    }
}
