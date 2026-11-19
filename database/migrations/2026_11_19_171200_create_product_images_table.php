<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('path', 1024);
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['product_id', 'deleted_at', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
