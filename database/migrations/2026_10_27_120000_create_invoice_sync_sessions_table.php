<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sync_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('run_id')->unique();
            $table->json('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sync_sessions');
    }
};
