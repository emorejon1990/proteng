<?php

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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('serial')->nullable();
            $table->bigInteger('assambly_by')->unsigned()->nullable();
            $table->foreign('assambly_by')->references('id')->on('users');
            $table->dateTime('assambly_date')->nullable();
            $table->boolean('assambled')->default(false);
            $table->bigInteger('fill_by')->unsigned()->nullable();
            $table->foreign('fill_by')->references('id')->on('users');
            $table->dateTime('fill_date')->nullable();
            $table->boolean('filled')->default(false);
            $table->float('weight')->nullable();
            $table->bigInteger('quality_by')->unsigned()->nullable();
            $table->foreign('quality_by')->references('id')->on('users');
            $table->dateTime('quality_date')->nullable();
            $table->boolean('qualified')->default(false);
            $table->float('f_weight')->nullable();
            $table->bigInteger('status_id')->unsigned()->nullable();
            $table->foreign('status_id')->references('id')->on('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
