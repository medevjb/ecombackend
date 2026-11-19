<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->char('combination_key', 64);
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('price_override_amount')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_id', 'combination_key']);
            $table->index(['product_id', 'status', 'deleted_at']);
        });

        Schema::table('attribute_values', function (Blueprint $table): void {
            $table->unique(['id', 'attribute_id'], 'attribute_option_owner_unique');
        });

        Schema::create('variant_attribute_values', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('attribute_value_id');
            $table->foreign(['attribute_value_id', 'attribute_id'], 'variant_option_owner_foreign')
                ->references(['id', 'attribute_id'])->on('attribute_values')->restrictOnDelete();
            $table->primary(['product_variant_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_values');
        Schema::dropIfExists('product_variants');
        Schema::table('attribute_values', function (Blueprint $table): void {
            $table->dropUnique('attribute_option_owner_unique');
        });
    }
};
