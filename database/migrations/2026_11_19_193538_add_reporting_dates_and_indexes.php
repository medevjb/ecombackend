<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['created_at', 'id']);
            $table->index('paid_at');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('captured_at')->nullable()->index();
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->index();
        });
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->index('redeemed_at');
        });

        DB::table('payments')->where('captured_amount', '>', 0)->update(['captured_at' => DB::raw(
            'COALESCE((SELECT paid_at FROM orders WHERE orders.id = payments.order_id), payments.updated_at)'
        )]);
        DB::table('refunds')->where('status', 'succeeded')->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', fn (Blueprint $table) => $table->dropIndex(['redeemed_at']));
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex(['completed_at']);
            $table->dropColumn('completed_at');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['captured_at']);
            $table->dropColumn('captured_at');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'id']);
            $table->dropIndex(['paid_at']);
        });
    }
};
