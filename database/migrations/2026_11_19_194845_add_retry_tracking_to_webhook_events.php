<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_redispatched_at')->nullable();
            $table->index(['processed_at', 'failed_at', 'last_redispatched_at', 'id'], 'webhook_recovery_queue');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropIndex('webhook_recovery_queue');
            $table->dropColumn(['failed_at', 'last_redispatched_at']);
        });
    }
};
