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
        Schema::table('invoices', function (Blueprint $table) {


            $table->foreignId('customer_id')
                ->cascadeOnDelete()->change();


            $table->string('invoice_number')->nullable();

            $table->decimal('total', 12, 2)->default(0)->change();
            $table->decimal('balance', 12, 2)->default(0);

            $table->enum('status', ['open', 'paid', 'void'])->default('open')->change();

            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();

            $table->json('metadata')->nullable();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
