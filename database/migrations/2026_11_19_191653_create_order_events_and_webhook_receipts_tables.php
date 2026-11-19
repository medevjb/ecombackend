<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_event_id')->unique();
            $table->string('type', 100);
            $table->string('object_id');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('webhook_event_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider_status', 40);
            $table->string('outcome', 80);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 16);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->string('reason', 500)->nullable();
            $table->string('request_id', 40)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('webhook_events');
    }
};
