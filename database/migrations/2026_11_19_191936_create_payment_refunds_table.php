<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('purpose', 24);
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('reason', 500);
            $table->char('idempotency_hash', 64);
            $table->string('provider_refund_id')->nullable()->unique();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'idempotency_hash']);
            $table->index(['order_id', 'status']);
        });

        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropUnique(['order_id']);
            $table->dropColumn('order_id');
        });
        Schema::dropIfExists('refunds');
    }
};
