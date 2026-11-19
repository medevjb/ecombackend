<?php

namespace Database\Seeders;

use HasinHayder\Tyro\Models\Privilege;
use HasinHayder\Tyro\Models\Role;
use HasinHayder\Tyro\Support\TyroCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffAccessSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = [];

            foreach (config('staff-access.permissions') as $slug) {
                $permission = Privilege::query()->firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)]);

                $permissions[$slug] = $permission->getKey();
            }

            foreach (config('staff-access.roles') as $slug => $grants) {
                $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)]);
                $role->privileges()->syncWithoutDetaching(array_values(array_intersect_key($permissions, array_flip($grants))));
                TyroCache::forgetUsersByRole($role);
            }
        });
    }
}
