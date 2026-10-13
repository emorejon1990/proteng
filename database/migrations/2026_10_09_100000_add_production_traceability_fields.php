<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('production_work_order_id')->nullable()
                ->constrained('work_orders')->restrictOnDelete();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->boolean('review_receiving_open')->default(false);
            $table->dateTime('review_closed_at')->nullable();
            $table->foreignId('review_closed_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('production_work_order_id')->exists()
            || DB::table('work_orders')->where('review_receiving_open', true)->exists()
            || DB::table('work_orders')->whereNotNull('review_closed_at')->exists()
            || DB::table('work_orders')->whereNotNull('review_closed_by')->exists()) {
            throw new RuntimeException('Traceability data exists. Rollback would discard it.');
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_closed_by');
            $table->dropColumn(['review_receiving_open', 'review_closed_at']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_work_order_id');
        });
    }
};
