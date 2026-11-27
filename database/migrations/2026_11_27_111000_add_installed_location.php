<?php

use App\Services\ProductionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $locations = DB::table('locations')->whereRaw('LOWER(TRIM(name)) = ?', ['installed'])->count();
            $statuses = DB::table('status')->whereRaw('LOWER(TRIM(name)) = ?', ['installed'])->count();
            if ($locations > 1 || $statuses > 1) {
                throw new RuntimeException('Installed catalog entries are ambiguous.');
            }

            $entry = ['name' => 'Installed', 'created_at' => now(), 'updated_at' => now()];
            if ($locations === 0) {
                $stock = DB::table('locations')->find(app(ProductionCatalog::class)->id('stock_location'));
                DB::table('locations')->insert($entry + ['wh' => $stock->wh]);
            }
            if ($statuses === 0) {
                DB::table('status')->insert($entry);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Installed locations must be retained. Rollback requires a data-preserving plan.');
    }
};
