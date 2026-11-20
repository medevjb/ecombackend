<?php

namespace Database\Seeders;

use App\Models\User;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $accounts = [
            'super-admin' => 'admin@admin.com',
            'admin' => 'admin@demo.test',
            'product-manager' => 'product-manager@demo.test',
            'order-manager' => 'order-manager@demo.test',
            'support' => 'support@demo.test',
            'customer' => 'customer@demo.test',
        ];

        DB::transaction(function () use ($accounts): void {
            foreach ($accounts as $slug => $email) {
                $user = User::query()->firstOrCreate(['email' => $email], [
                    'name' => Str::headline($slug),
                    'password' => 'password',
                ]);
                if (! $user->wasRecentlyCreated) {
                    continue;
                }

                $user->forceFill(['email_verified_at' => now()])->save();
                $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)]);
                $user->assignRole($role);
                if ($slug !== 'customer') {
                    $user->staffIdentity()->create();
                }
            }
        });
    }
}
