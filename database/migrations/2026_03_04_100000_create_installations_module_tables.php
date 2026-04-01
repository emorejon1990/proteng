<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('customer_quickbooks_id')->nullable();

            $table->foreignId('inst_manager_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('worker_user_id')->constrained('users')->restrictOnDelete();

            $table->enum('status', ['draft', 'scheduled', 'in_progress', 'completed', 'canceled'])
                ->default('draft');

            $table->timestamp('performed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['worker_user_id', 'status']);
        });

        Schema::create('equipment_installation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->string('img')->nullable();
            $table->timestamps();

            $table->index(['equipment_id', 'sort_order']);
        });

        Schema::create('installation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('img')->nullable();
            $table->timestamps();

            $table->index(['installation_id', 'sort_order']);
        });

        Schema::create('installation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('installation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_assignments');
        Schema::dropIfExists('installation_steps');
        Schema::dropIfExists('equipment_installation_steps');
        Schema::dropIfExists('installations');
    }
};
