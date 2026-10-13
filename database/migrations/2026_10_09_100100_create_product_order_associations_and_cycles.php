<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('state', 24)->default('recorded');
            $table->foreignId('associated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('associated_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['work_order_id', 'product_id', 'purpose'], 'wop_order_product_purpose_unique');
            $table->unique(['id', 'product_id'], 'wop_id_product_unique');
            $table->index(['work_order_id', 'purpose', 'state'], 'wop_order_purpose_state_index');
            $table->index(['product_id', 'state'], 'wop_product_state_index');
        });

        Schema::create('product_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedBigInteger('work_order_product_id')->nullable();
            $table->foreign(['work_order_product_id', 'product_id'], 'cycles_association_product_fk')
                ->references(['id', 'product_id'])->on('work_order_products')->restrictOnDelete();
            $table->unsignedInteger('cycle_number');
            $table->string('source', 20)->default('production');
            $table->foreignId('asset_id')->nullable()->constrained('asset')->restrictOnDelete();
            $table->decimal('fill_weight', 14, 4)->nullable();
            $table->decimal('quality_weight', 14, 4)->nullable();
            $table->boolean('fill_completed')->nullable()->default(false);
            $table->boolean('quality_completed')->nullable()->default(false);
            $table->string('quality_result', 20)->nullable();
            $table->foreignId('fill_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('quality_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('fill_date')->nullable();
            $table->dateTime('quality_date')->nullable();
            // Waiting remains calendar-date based, including repaired products.
            $table->date('waiting_started_on')->nullable();
            $table->date('quality_available_on')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('legacy_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'cycle_number'], 'cycles_product_number_unique');
            $table->unique(['id', 'product_id'], 'cycles_id_product_unique');
            $table->index(['work_order_product_id', 'quality_result'], 'cycles_association_result_index');
        });
    }

    public function down(): void
    {
        if (DB::table('product_cycles')->exists() || DB::table('work_order_products')->exists()) {
            throw new RuntimeException('Associations or cycles exist. Rollback would discard history.');
        }
        Schema::dropIfExists('product_cycles');
        Schema::dropIfExists('work_order_products');
    }
};
