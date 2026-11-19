<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cart_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->char('idempotency_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->char('cart_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_hash']);
            $table->index('expires_at');
        });

        Schema::create('order_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('next_number');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 24)->unique();
            $table->foreignId('checkout_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('shipping_amount');
            $table->unsignedBigInteger('tax_amount');
            $table->unsignedBigInteger('total_amount');
            $table->string('coupon_code', 40)->nullable();
            $table->string('shipping_carrier', 80)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['status', 'created_at', 'id']);
        });

        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->foreignId('order_id')->primary()->constrained()->restrictOnDelete();
            $table->string('recipient', 150);
            $table->string('line1', 200);
            $table->string('line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 32);
            $table->char('country', 2);
            $table->string('phone', 35)->nullable();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->ulid('product_public_id');
            $table->ulid('variant_public_id');
            $table->string('product_name');
            $table->string('sku', 100);
            $table->json('options');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('line_amount');
            $table->unique(['order_id', 'product_variant_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider_intent_id')->nullable()->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('expected_amount');
            $table->unsignedBigInteger('captured_amount')->default(0);
            $table->char('currency', 3);
            $table->string('exception_code', 80)->nullable();
            $table->timestamp('exception_notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
        Schema::dropIfExists('checkout_attempts');
    }
};
