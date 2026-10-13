<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedBigInteger('work_order_product_id');
            $table->foreign(['work_order_product_id', 'product_id'], 'repairs_association_product_fk')
                ->references(['id', 'product_id'])->on('work_order_products')->restrictOnDelete();
            $table->unsignedBigInteger('rejected_cycle_id')->nullable();
            $table->foreign(['rejected_cycle_id', 'product_id'], 'repairs_cycle_product_fk')
                ->references(['id', 'product_id'])->on('product_cycles')->restrictOnDelete();
            $table->text('diagnosis')->nullable();
            $table->text('repair_description')->nullable();
            $table->text('recycling_reason')->nullable();
            $table->string('result', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('work_order_product_id', 'repairs_association_unique');
            $table->unique(['id', 'product_id'], 'repairs_id_product_unique');
            $table->index(['product_id', 'result'], 'repairs_product_result_index');
        });

        Schema::table('product_cycles', function (Blueprint $table) {
            $table->unsignedBigInteger('product_repair_id')->nullable()->unique();
            $table->foreign(['product_repair_id', 'product_id'], 'cycles_repair_product_fk')
                ->references(['id', 'product_id'])->on('product_repairs')->restrictOnDelete();
        });

        Schema::create('product_active_assignments', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained('products')->restrictOnDelete();
            $table->unsignedBigInteger('work_order_product_id')->nullable()->unique();
            $table->foreign(['work_order_product_id', 'product_id'], 'active_association_product_fk')
                ->references(['id', 'product_id'])->on('work_order_products')->restrictOnDelete();
            $table->unsignedBigInteger('product_cycle_id')->nullable();
            $table->foreign(['product_cycle_id', 'product_id'], 'active_cycle_product_fk')
                ->references(['id', 'product_id'])->on('product_cycles')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('product_active_assignments')->exists()
            || DB::table('product_repairs')->exists()
            || DB::table('product_cycles')->whereNotNull('product_repair_id')->exists()) {
            throw new RuntimeException('Repair or assignment data exists. Rollback would discard it.');
        }
        Schema::dropIfExists('product_active_assignments');
        Schema::table('product_cycles', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['product_repair_id', 'product_id'] : 'cycles_repair_product_fk');
            $table->dropUnique(['product_repair_id']);
            $table->dropColumn('product_repair_id');
        });
        Schema::dropIfExists('product_repairs');
    }
};
