<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_contents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 32);
            $table->string('key', 80);
            $table->json('data');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'key']);
            $table->index(['type', 'published', 'sort_order', 'id']);
        });

        DB::table('storefront_contents')->insert([
            'public_id' => (string) Str::ulid(), 'type' => 'settings', 'key' => 'site', 'data' => '{}',
            'published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_contents');
    }
};
