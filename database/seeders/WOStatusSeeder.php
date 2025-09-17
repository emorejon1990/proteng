<?php

namespace Database\Seeders;

use App\Models\WOStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WOStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        WOStatus::create([
            'name' => 'New'
        ]);
        WOStatus::create([
            'name' => 'Approved'
        ]);
        WOStatus::create([
            'name' => 'inProcess'
        ]);
        WOStatus::create([
            'name' => 'Waiting'
        ]);
        WOStatus::create([
            'name' => 'Paused'
        ]);
        WOStatus::create([
            'name' => 'Ready'
        ]);
    }
}
