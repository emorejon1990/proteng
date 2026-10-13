<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_products', function (Blueprint $table) {
            $table->unsignedBigInteger('rejected_cycle_id')->nullable();
            $table->unique('rejected_cycle_id', 'wop_rejected_cycle_unique');
            $table->foreign(['rejected_cycle_id', 'product_id'], 'wop_rejected_cycle_product_fk')
                ->references(['id', 'product_id'])->on('product_cycles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('work_order_products')->whereNotNull('rejected_cycle_id')->exists()) {
            throw new RuntimeException('Review associations exist; rollback would discard rejection traceability.');
        }
        Schema::table('work_order_products', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['rejected_cycle_id', 'product_id'] : 'wop_rejected_cycle_product_fk');
            $table->dropUnique('wop_rejected_cycle_unique');
            $table->dropColumn('rejected_cycle_id');
        });
    }
};
