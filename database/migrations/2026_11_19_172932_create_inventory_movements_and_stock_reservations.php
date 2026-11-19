<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('type', 24);
            $table->bigInteger('delta');
            $table->unsignedInteger('on_hand_after');
            $table->string('reason', 500);
            $table->char('event_key', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['inventory_id', 'created_at', 'id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->char('payment_key', 64)->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->char('restoration_key', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at', 'id']);
        });

        Schema::create('stock_reservation_items', function (Blueprint $table): void {
            $table->foreignId('stock_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->primary(['stock_reservation_id', 'inventory_id']);
        });

        DB::table('inventories')->insertUsing(
            ['product_variant_id', 'on_hand', 'reserved'],
            DB::table('product_variants')->select('id')->selectRaw('0, 0')->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('inventories')->whereColumn('inventories.product_variant_id', 'product_variants.id');
            }),
        );

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_available_stock_check CHECK (reserved <= on_hand)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventories DROP CHECK inventories_available_stock_check');
        }

        Schema::dropIfExists('stock_reservation_items');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('inventory_movements');
    }
};
