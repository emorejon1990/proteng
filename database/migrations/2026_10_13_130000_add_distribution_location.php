<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $locations = DB::table('locations')->whereRaw('LOWER(TRIM(name)) = ?', ['distribution'])->get();
            if ($locations->count() > 1) {
                throw new RuntimeException('Distribution location is ambiguous.');
            }
            if ($locations->count() === 1) {
                return;
            }
            $stock = DB::table('locations')->whereRaw('LOWER(TRIM(name)) = ?', ['stock'])->get();
            if ($stock->count() !== 1) {
                throw new RuntimeException('A unique Stock location is required to identify the Distribution warehouse.');
            }
            DB::table('locations')->insert(['name' => 'Distribution', 'wh' => $stock->first()->wh, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Distribution locations must be retained; rollback requires a data-preserving plan.');
    }
};
