<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_email_verification_codes', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('email_verification');
            $table->char('challenge_hash', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_email_verification_codes', function (Blueprint $table): void {
            $table->dropUnique(['challenge_hash']);
            $table->dropColumn(['purpose', 'challenge_hash']);
        });
    }
};
