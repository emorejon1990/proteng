<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('customer_quickbooks_id')->nullable();
            $table->foreign('customer_quickbooks_id')
                ->references('quickbooks_id')
                ->on('customers')
                ->nullOnDelete();
        });

        if (Schema::hasColumn('work_orders', 'customer_id')) {
            DB::table('work_orders')
                ->whereNotNull('customer_id')
                ->join('customers', 'work_orders.customer_id', '=', 'customers.id')
                ->update(['work_orders.customer_quickbooks_id' => DB::raw('customers.quickbooks_id')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['customer_quickbooks_id']);
            $table->dropColumn('customer_quickbooks_id');
        });
    }
};
