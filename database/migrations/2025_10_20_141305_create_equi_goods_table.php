<?php

use App\Models\Request;
use App\Models\Equipment;
use App\Models\Goods;
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
        Schema::create('equi_goods', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Equipment::class);
            $table->foreignIdFor(Goods::class);
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equi_goods');
    }
};
