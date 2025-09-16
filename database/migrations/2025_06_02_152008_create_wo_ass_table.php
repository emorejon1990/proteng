<?php

use App\Models\Asset;
use App\Models\WorkOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wo_ass', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(WorkOrder::class);
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
        Schema::dropIfExists('wo_ass');
    }
};
