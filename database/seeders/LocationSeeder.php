<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Location::create([
            'name' => 'Production',
            'wh' => '1'
        ]);
        Location::create([
            'name' => 'Waiting',
            'wh' => '1'
        ]);
        Location::create([
            'name' => 'Quality',
            'wh' => '1'
        ]);
        Location::create([
            'name' => 'Stock',
            'wh' => '1'
        ]);
        Location::create([
            'name' => 'Recycle',
            'wh' => '1'
        ]);
    }
}
