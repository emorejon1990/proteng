<?php

namespace Database\Seeders;

use App\Models\WOType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WOTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        WOType::create([
            'name' => 'Production'
        ]);
        WOType::create([
            'name' => 'Distribution'
        ]);
        WOType::create([
            'name' => 'Installation'
        ]);
    }
}
