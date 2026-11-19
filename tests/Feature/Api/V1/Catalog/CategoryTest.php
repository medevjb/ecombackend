<?php

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\StaffUser;
use Database\Seeders\StaffAccessSeeder;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

function categoryStaffToken(string $role): string
{
    $staff = StaffUser::factory()->create();
    $staff->account->assignRole(Role::query()->where('slug', $role)->firstOrFail());

    return $staff->createToken('catalog')->plainTextToken;
}

it('lists only visible category trees and hides descendants of inactive or deleted parents', function () {
    $root = Category::factory()->create(['slug' => 'clothing']);
    $child = Category::factory()->for($root, 'parent')->create(['slug' => 'shirts']);
    $hidden = Category::factory()->create(['slug' => 'hidden', 'status' => CatalogStatus::Inactive]);
    Category::factory()->for($hidden, 'parent')->create(['slug' => 'hidden-child']);

    $this->getJson('/api/v1/categories')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $root->public_id)
        ->assertJsonPath('data.0.children.0.id', $child->public_id);
    $this->getJson('/api/v1/categories/hidden-child')->assertNotFound();

    $root->delete();
    $this->getJson('/api/v1/categories/shirts')->assertNotFound();
});

it('prevents cycles and duplicate slugs and audits authorized category edits', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(categoryStaffToken('product-manager'));
    $root = Category::factory()->create();
    $child = Category::factory()->for($root, 'parent')->create();

    $this->patchJson('/api/v1/admin/categories/'.$root->public_id, ['parent_id' => $child->public_id])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id', 'errors.fields');

    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Duplicate', 'slug' => $root->slug])
        ->assertUnprocessable()->assertJsonValidationErrors('slug', 'errors.fields');

    Auth::forgetGuards();
    $created = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Jackets',
        'slug' => 'jackets',
        'parent_id' => $root->public_id,
        'created_by' => 999,
    ])->assertCreated()->assertJsonPath('data.parent_id', $root->public_id)->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('tyro_audit_logs', ['event' => 'category.created']);
    $this->assertDatabaseMissing('categories', ['public_id' => $created->json('data.id'), 'created_by' => 999]);
});

it('reorders complete sibling groups and rejects incomplete requests', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(categoryStaffToken('product-manager'));
    [$one, $two] = Category::factory()->count(2)->create()->all();

    $this->patchJson('/api/v1/admin/categories/reorder', ['ids' => [$two->public_id]])
        ->assertUnprocessable()->assertJsonValidationErrors('ids', 'errors.fields');
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/categories/reorder', ['ids' => [$two->public_id, $one->public_id]])
        ->assertNoContent();

    $this->getJson('/api/v1/categories')->assertJsonPath('data.0.id', $two->public_id)
        ->assertJsonPath('data.1.id', $one->public_id);
});

it('returns partial bulk results and restores archived categories', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(categoryStaffToken('product-manager'));
    $category = Category::factory()->create();
    $missing = (string) Str::ulid();

    $this->postJson('/api/v1/admin/categories/bulk-actions', [
        'action' => 'delete',
        'ids' => [$category->public_id, $missing],
    ])->assertOk()->assertJsonPath('data.succeeded_ids', [$category->public_id])
        ->assertJsonPath('data.failed.0.code', 'NOT_FOUND');
    $this->assertSoftDeleted($category);

    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/categories/'.$category->public_id.'/restore')->assertOk();
    $this->assertNotSoftDeleted($category);
});

it('allows support to view categories but denies changes', function () {
    $this->seed(StaffAccessSeeder::class);
    $this->withToken(categoryStaffToken('support'));
    $this->getJson('/api/v1/admin/categories')->assertOk();

    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Invalid', 'slug' => 'invalid'])->assertForbidden();

    $category = Category::factory()->create();
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/categories/bulk-actions', ['action' => 'delete', 'ids' => [$category->public_id]])
        ->assertOk()->assertJsonPath('data.failed.0.code', 'FORBIDDEN');
    $this->assertNotSoftDeleted($category);
});
