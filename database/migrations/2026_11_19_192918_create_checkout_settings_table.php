<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_settings', function (Blueprint $table): void {
            $table->string('key', 50)->primary();
            $table->unsignedInteger('amount')->nullable();
        });

        DB::table('checkout_settings')->insert(['key' => 'flat_shipping_amount']);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_settings');
    }
};
