<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refill_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->json('data');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('refill_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refill_operation_id')->constrained('refill_operations')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->unique()->constrained('products')->restrictOnDelete();
            $table->foreignId('serial_id')->constrained('serial')->restrictOnDelete();
            $table->string('serial', 9);
            $table->unsignedBigInteger('original_product_id');
            $table->unsignedBigInteger('origin_history_id');
            $table->json('snapshot');
            $table->json('origin_history');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('refill_operations')->exists() || DB::table('refill_entries')->exists()) {
            throw new RuntimeException('Refill audit data exists; rollback would discard it.');
        }
        Schema::dropIfExists('refill_entries');
        Schema::dropIfExists('refill_operations');
    }
};
