<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 40)->unique();
            $table->string('type', 16);
            $table->unsignedBigInteger('value');
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('minimum_amount')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 16)->default('inactive');
            $table->unsignedInteger('global_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'deleted_at', 'id']);
        });

        Schema::create('coupon_product', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->primary(['coupon_id', 'product_id']);
        });

        Schema::create('category_coupon', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->primary(['coupon_id', 'category_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->ulid('order_reference')->unique();
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('discount_amount');
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'status', 'expires_at'], 'coupon_usage_index');
            $table->index(['coupon_id', 'user_id', 'status', 'expires_at'], 'coupon_customer_usage_index');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('category_coupon');
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupons');
    }
};
