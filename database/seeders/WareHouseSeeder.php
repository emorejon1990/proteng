<?php

namespace Database\Seeders;

use App\Models\WareHouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WareHouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        WareHouse::create([
            'name' => 'Jupiter',
            'address' => '1234 NW'
        ]);
    }
}
