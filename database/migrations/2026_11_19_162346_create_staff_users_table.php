<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_users', function (Blueprint $table): void {
            $table->foreignId('id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        DB::table('staff_users')->insertUsing(['id', 'created_at', 'updated_at'], DB::table('users')
            ->select(['users.id', 'users.created_at', 'users.updated_at'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->whereColumn('user_roles.user_id', 'users.id')
                    ->whereNotIn('roles.slug', ['customer', 'user']);
            }));
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
    }
};
