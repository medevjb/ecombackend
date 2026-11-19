<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->unsignedBigInteger('regular_amount');
            $table->unsignedBigInteger('sale_amount')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('status', 16)->default('draft');
            $table->boolean('featured')->default(false);
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'deleted_at', 'published_at', 'id'], 'products_publication_index');
        });

        Schema::create('category_product', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('products');
    }
};
