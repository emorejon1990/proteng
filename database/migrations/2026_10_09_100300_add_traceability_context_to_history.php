<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('history', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->unsignedBigInteger('work_order_product_id')->nullable();
            $table->foreign(['work_order_product_id', 'products_id'], 'history_association_product_fk')
                ->references(['id', 'product_id'])->on('work_order_products')->restrictOnDelete();
            $table->unsignedBigInteger('product_cycle_id')->nullable();
            $table->foreign(['product_cycle_id', 'products_id'], 'history_cycle_product_fk')
                ->references(['id', 'product_id'])->on('product_cycles')->restrictOnDelete();
            $table->unsignedBigInteger('product_repair_id')->nullable();
            $table->foreign(['product_repair_id', 'products_id'], 'history_repair_product_fk')
                ->references(['id', 'product_id'])->on('product_repairs')->restrictOnDelete();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('history')->where(function ($query) {
            $query->whereNotNull('work_order_id')->orWhereNotNull('work_order_product_id')
                ->orWhereNotNull('product_cycle_id')->orWhereNotNull('product_repair_id')
                ->orWhereNotNull('metadata');
        })->exists()) {
            throw new RuntimeException('History context exists. Rollback would discard it.');
        }
        Schema::table('history', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['work_order_product_id', 'products_id'] : 'history_association_product_fk');
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['product_cycle_id', 'products_id'] : 'history_cycle_product_fk');
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['product_repair_id', 'products_id'] : 'history_repair_product_fk');
            $table->dropConstrainedForeignId('work_order_id');
            $table->dropColumn(['work_order_product_id', 'product_cycle_id', 'product_repair_id', 'metadata']);
        });
    }
};
