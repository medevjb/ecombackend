<?php

use App\Models\StaffUser;
use Database\Seeders\StaffAccessSeeder;
use HasinHayder\Tyro\Models\AuditLog;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Support\Facades\Auth;

function phaseTwoStaff(string ...$roles): StaffUser
{
    $staff = StaffUser::factory()->create();

    foreach ($roles as $slug) {
        $staff->account->assignRole(Role::query()->where('slug', $slug)->firstOrFail());
    }

    return $staff;
}

it('combines staff permissions across roles and restricts security mutations', function () {
    $this->seed(StaffAccessSeeder::class);
    $staff = phaseTwoStaff('product-manager', 'order-manager');
    $this->withToken($staff->createToken('client')->plainTextToken)->getJson('/api/v1/admin/me')
        ->assertOk()
        ->assertJsonFragment(['inventory.adjust'])
        ->assertJsonFragment(['orders.update_status']);

    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/staff')->assertForbidden();

    Auth::forgetGuards();
    $admin = phaseTwoStaff('admin');
    $this->withToken($admin->createToken('admin')->plainTextToken)->getJson('/api/v1/admin/roles')->assertOk();

    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/roles', ['name' => 'Custom', 'slug' => 'custom'])->assertForbidden();
});

it('protects the system role and the last active super admin', function () {
    $this->seed(StaffAccessSeeder::class);
    $staff = phaseTwoStaff('super-admin');
    $this->withToken($staff->createToken('root')->plainTextToken);

    $this->patchJson('/api/v1/admin/roles/super-admin', ['name' => 'Changed'])->assertConflict();
    Auth::forgetGuards();
    $this->deleteJson('/api/v1/admin/roles/super-admin')->assertConflict();
    Auth::forgetGuards();
    $this->putJson('/api/v1/admin/staff/'.$staff->account->public_id.'/roles', ['roles' => ['admin']])->assertConflict();
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/staff/'.$staff->account->public_id, ['active' => false])->assertConflict();

    expect($staff->account->refresh()->isSuspended())->toBeFalse();
    expect($staff->account->roles()->where('slug', 'super-admin')->exists())->toBeTrue();
});

it('applies permission changes to existing staff tokens and records the request ID', function () {
    $this->seed(StaffAccessSeeder::class);
    $root = phaseTwoStaff('super-admin');
    $rootToken = $root->createToken('root')->plainTextToken;

    $this->withToken($rootToken)->postJson('/api/v1/admin/roles', ['name' => 'Auditor', 'slug' => 'auditor'])
        ->assertCreated();
    $staff = phaseTwoStaff('auditor');
    $staffToken = $staff->createToken('auditor')->plainTextToken;

    Auth::forgetGuards();
    $this->withToken($staffToken)->getJson('/api/v1/admin/activity-logs')->assertForbidden();

    Auth::forgetGuards();
    $response = $this->withToken($rootToken)->putJson('/api/v1/admin/roles/auditor/permissions', ['permissions' => ['audit.view']])
        ->assertOk()->assertJsonPath('data.permissions', ['audit.view']);

    $entry = AuditLog::query()->where('event', 'role.permissions.replaced')->firstOrFail();
    expect($entry->metadata['request_id'])->toBe($response->json('request_id'));

    Auth::forgetGuards();
    $this->withToken($staffToken)->getJson('/api/v1/admin/activity-logs')->assertOk();

    Auth::forgetGuards();
    $this->withToken($rootToken)->putJson('/api/v1/admin/roles/auditor/permissions', ['permissions' => []])->assertOk();

    Auth::forgetGuards();
    $this->withToken($staffToken)->getJson('/api/v1/admin/activity-logs')->assertForbidden();
});

it('creates staff with a selected role and revokes sign-in tokens when suspended', function () {
    $this->seed(StaffAccessSeeder::class);
    $root = phaseTwoStaff('super-admin');

    $this->withToken($root->createToken('root')->plainTextToken);
    $response = $this->postJson('/api/v1/admin/staff', [
        'name' => 'New Support',
        'email' => 'new-support@example.test',
        'password' => 'SafePassword!123',
        'password_confirmation' => 'SafePassword!123',
        'roles' => ['support'],
    ])->assertCreated()->assertJsonPath('data.roles', ['support']);

    $staff = StaffUser::query()->whereHas('account', fn ($query) => $query->where('public_id', $response->json('data.id')))->firstOrFail();
    $token = $staff->createToken('old');
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/staff/'.$response->json('data.id'), ['active' => false])
        ->assertOk()->assertJsonPath('data.active', false);
    $this->assertModelMissing($token->accessToken);
});
