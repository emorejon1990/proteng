<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('invoices')->select('quickbooks_id')->groupBy('quickbooks_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate historical invoice QuickBooks IDs require manual review; no data was deleted.');
        }
        $uniqueExternalId = collect(Schema::getIndexes('invoices'))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === ['quickbooks_id']
        );
        if (! $uniqueExternalId) {
            Schema::table('invoices', fn (Blueprint $table) => $table->unique('quickbooks_id', 'invoices_quickbooks_id_phase9_unique'));
        }
        // Existing installations and catalog identifiers are preserved.
        if (! DB::table('wo_type')->whereRaw('LOWER(TRIM(name)) = ?', ['installation'])->exists()) {
            DB::table('wo_type')->insert(['name' => 'Installation', 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
        });
        Schema::table('asset', function (Blueprint $table) {
            $table->string('quickbooks_item_type')->nullable();
            $table->boolean('quickbooks_active')->nullable();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('realm_id')->nullable();
            $table->string('operation_type')->nullable();
            $table->string('operation_status')->default('unclassified')->index();
            $table->timestamp('classified_at')->nullable();
            $table->foreignId('classified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->unique()->constrained('work_orders')->restrictOnDelete();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->decimal('tax_total', 18, 2)->nullable();
            $table->decimal('discount_total', 18, 2)->nullable();
            $table->timestamp('quickbooks_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('operation_snapshot')->nullable();
        });
        Schema::table('installations', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->unique()->constrained('work_orders')->restrictOnDelete();
        });
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('quickbooks_line_id')->nullable();
            $table->unsignedInteger('line_number');
            $table->string('detail_type');
            $table->string('quickbooks_item_id')->nullable()->index();
            $table->string('item_type')->nullable();
            $table->foreignId('asset_id')->nullable()->constrained('asset')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 6)->nullable();
            $table->decimal('unit_price', 18, 6)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('tax_code')->nullable();
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['invoice_id', 'line_number']);
        });
        Schema::create('invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('installation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 9 preserves accounting and operation audit data; use a reviewed forward migration.');
    }
};
