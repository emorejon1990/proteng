<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Asset::create([
            'name' => 'THIA-S30',
            'weight' => '163',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-S50',
            'weight' => '188',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-S100',
            'weight' => '250',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-S150',
            'weight' => '313',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-S250',
            'weight' => '438',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-S500',
            'weight' => '750',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-S1000',
            'weight' => '1360',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD100',
            'weight' => '272',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD200',
            'weight' => '424',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD300',
            'weight' => '575',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD400',
            'weight' => '727',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD1000',
            'weight' => '1636',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD1500',
            'weight' => '2394',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA-HD2000',
            'weight' => '3152',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA+HD/SD.5',
            'weight' => '1680',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA+HD/SD.75',
            'weight' => '2400',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA+HD/SD1',
            'weight' => '2990',
            'weight_tolerance' => '0.5'
        ]);
        Asset::create([
            'name' => 'THIA+HD/SD2',
            'weight' => '4880',
            'weight_tolerance' => '0.5'
        ]);
    }
}
