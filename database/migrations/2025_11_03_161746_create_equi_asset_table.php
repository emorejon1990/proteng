<?php

use App\Models\Asset;
use App\Models\Equipment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('equi_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Equipment::class);
            $table->foreignIdFor(Asset::class);
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equi_asset');
    }
};
