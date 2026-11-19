<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_credentials', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('enrollment_expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->json('recovery_codes')->nullable();
        });

        Schema::create('staff_login_challenges', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('device_name', 100);
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_login_challenges');
        Schema::dropIfExists('two_factor_credentials');
    }
};
