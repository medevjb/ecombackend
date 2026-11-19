<?php

use App\Models\StaffUser;
use App\Models\User;
use HasinHayder\Tyro\Models\AuditLog;
use HasinHayder\Tyro\Models\Role;

function auditLogManagementToken(): string
{
    $user = User::factory()->create();
    $user->assignRole(Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']));

    return StaffUser::factory()->for($user, 'account')->create()->createToken('test', ['super-admin'])->plainTextToken;
}

it('filters audit entries and paginates tied timestamps in ID order', function () {
    $first = AuditLog::query()->create([
        'event' => 'example.first',
        'created_at' => '2026-01-02 15:00:00',
        'new_values' => ['private' => 'sensitive value'],
    ]);
    AuditLog::query()->create(['event' => 'example.second', 'created_at' => '2026-01-02 15:00:00']);
    AuditLog::query()->create(['event' => 'example.excluded', 'created_at' => '2026-01-03 00:00:00']);

    $this->withToken(auditLogManagementToken())->getJson('/api/v1/admin/audit-logs?event=example.&to=2026-01-02&sort_direction=asc&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('meta.pagination', ['current_page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2])
        ->assertJsonMissingPath('data.0.new_values')
        ->assertDontSee('sensitive value');
});

it('rejects unsafe sorts, oversized pages, and inverted date ranges with 422', function () {
    $this->withToken(auditLogManagementToken())->getJson('/api/v1/admin/audit-logs?sort_by=metadata&per_page=101&from=2026-01-02&to=2026-01-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort_by', 'per_page', 'to'], 'errors.fields');
});
