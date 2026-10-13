<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $matches = DB::table('locations')->whereRaw('LOWER(TRIM(name)) = ?', ['review'])->get();
            if ($matches->count() > 1) {
                throw new RuntimeException('Review location is ambiguous; reconcile the catalog before migrating.');
            }
            if ($matches->count() === 1) {
                return;
            }
            $quality = DB::table('locations')->whereRaw('LOWER(TRIM(name)) = ?', ['quality'])->get();
            if ($quality->count() !== 1) {
                throw new RuntimeException('A unique Quality location is required to identify the Review warehouse.');
            }
            DB::table('locations')->insert([
                'name' => 'Review', 'wh' => $quality->first()->wh, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Review locations are retained; rollback requires an explicit data-preserving plan.');
    }
};
