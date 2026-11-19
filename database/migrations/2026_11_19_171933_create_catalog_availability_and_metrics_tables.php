<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();
        });

        Schema::create('product_metrics', function (Blueprint $table): void {
            $table->foreignId('product_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paid_quantity')->default(0)->index();
            $table->unsignedInteger('approved_rating_count')->default(0);
            $table->unsignedBigInteger('approved_rating_total')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_metrics');
        Schema::dropIfExists('inventories');
    }
};
