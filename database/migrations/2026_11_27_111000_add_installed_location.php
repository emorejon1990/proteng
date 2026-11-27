<?php

use App\Services\ProductionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $locations = DB::table('locations')->whereRaw('LOWER(TRIM(name)) = ?', ['installed'])->get();
        if ($locations->count() > 1) {
            throw new RuntimeException('Installed location is ambiguous.');
        }
        if ($locations->isNotEmpty()) {
            return;
        }

        $stock = DB::table('locations')->find(app(ProductionCatalog::class)->id('stock_location'));
        DB::table('locations')->insert([
            'name' => 'Installed', 'wh' => $stock->wh, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Installed locations must be retained. Rollback requires a data-preserving plan.');
    }
};
