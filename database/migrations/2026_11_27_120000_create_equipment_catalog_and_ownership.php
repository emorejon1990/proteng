<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('name_key', 64)->unique();
            $table->timestamps();
        });
        Schema::create('equipment_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_brand_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('name_key', 64);
            $table->boolean('guide_needs_review')->default(false);
            $table->timestamps();
            $table->unique(['equipment_brand_id', 'name_key']);
        });
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('equipment_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('physical_location')->nullable();
            $table->string('operational_status')->nullable();
            $table->text('technical_notes')->nullable();
        });
        Schema::create('equipment_model_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_model_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->string('img')->nullable();
            $table->timestamps();
            $table->index(['equipment_model_id', 'sort_order']);
        });
        Schema::create('equipment_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('previous_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('new_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('transferred_at');
            $table->timestamps();
        });
        Schema::table('manuals', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_id')->nullable()->change();
            $table->foreignId('equipment_model_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Equipment catalog and ownership history require a data-preserving rollback plan.');
    }
};
