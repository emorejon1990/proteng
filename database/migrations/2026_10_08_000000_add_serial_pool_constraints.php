<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial', function (Blueprint $table) {
            $table->integer('status')->default(1)->change();
            $table->unique('serial');
            $table->index(['status', 'products_id']);
        });
    }

    public function down(): void
    {
        Schema::table('serial', function (Blueprint $table) {
            $table->dropUnique(['serial']);
            $table->dropIndex(['status', 'products_id']);
            $table->integer('status')->default(null)->change();
        });
    }
};
