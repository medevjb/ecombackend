<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->boolean('verified_purchase')->default(false);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'status', 'deleted_at', 'created_at', 'id'], 'reviews_public_listing');
            $table->index(['status', 'deleted_at', 'id'], 'reviews_moderation_listing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
