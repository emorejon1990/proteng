<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installed_products', function (Blueprint $table) {
            $table->foreignId('installation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['installation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Installation traceability must be retained. Rollback requires a data-preserving plan.');
    }
};
