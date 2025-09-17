<?php

namespace Database\Seeders;

use App\Models\WorkCenter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkCenterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        WorkCenter::create([
            'name' => 'Assemble'
        ]);
        WorkCenter::create([
            'name' => 'Fill'
        ]);
        WorkCenter::create([
            'name' => 'Waiting'
        ]);
        WorkCenter::create([
            'name' => 'Quality'
        ]);
    }
}
