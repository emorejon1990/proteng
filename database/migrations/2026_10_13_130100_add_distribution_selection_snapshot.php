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
            $table->string('distribution_serial')->nullable();
            $table->foreignId('distribution_asset_id')->nullable()->constrained('asset')->restrictOnDelete();
            $table->unsignedBigInteger('distribution_cycle_id')->nullable();
            $table->foreign(['distribution_cycle_id', 'product_id'], 'distribution_snapshot_cycle_product_fk')
                ->references(['id', 'product_id'])->on('product_cycles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('work_order_products')->whereNotNull('distribution_serial')->exists()) {
            throw new RuntimeException('Distribution selections exist; their snapshots must be retained.');
        }
        Schema::table('work_order_products', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['distribution_cycle_id', 'product_id'] : 'distribution_snapshot_cycle_product_fk');
            $table->dropForeign(['distribution_asset_id']);
            $table->dropColumn(['distribution_serial', 'distribution_asset_id', 'distribution_cycle_id']);
        });
    }
};
