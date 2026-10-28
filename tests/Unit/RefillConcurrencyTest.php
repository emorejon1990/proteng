<?php

use App\Http\Controllers\SerialController;
use App\Models\Products;
use App\Models\Serial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
uses(TestCase::class);

it('protects serial generation and duplicate scans across simultaneous real requests', function (string $kind) {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('Requires proc_open for independent test processes.');
    }
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proteng-refill-test-'.bin2hex(random_bytes(12)).'.sqlite';
    fclose(fopen($file, 'x'));
    $workers = [];
    try {
        app()->instance('env', 'testing');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file,
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'database.connections.sqlite.busy_timeout' => 5000, 'cache.default' => 'array', 'permission.cache.store' => 'array']);
        DB::purge('sqlite');
        createTraceabilityFixtures();
        phaseOneUp();
        (require database_path('migrations/2025_10_02_192820_create_permission_tables.php'))->up();
        (require database_path('migrations/2026_10_28_100000_create_refill_operations.php'))->up();
        app(PermissionRegistrar::class)->initializeCache();
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        User::findOrFail(1)->assignRole('Admin');
        DB::table('status')->insert(['id' => 61, 'name' => 'Ready']);
        DB::table('locations')->insert(['id' => 51, 'name' => 'Stock']);
        $serial = $kind === 'scan' ? SerialController::generatepool(1)->first()->serial : null;
        $data = ['asset_id' => 31, 'assambly_by' => 1, 'fill_by' => 1, 'quality_by' => 1,
            'assambled' => true, 'filled' => true, 'qualified' => true, 'status_id' => 61, 'location_id' => 51, 'weight' => 2500];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/refill-concurrency-worker.php')],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start isolated worker.');
            }
            $workers[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(compact('file', 'kind', 'serial', 'data'), JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
        }
        foreach ($workers as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 30);
            $ready = trim(fgets($pipes[1]) ?: '');
            if ($ready !== 'READY') {
                throw new RuntimeException('Worker startup failed: '.stream_get_contents($pipes[2]));
            }
        }
        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }
        $outcomes = [];
        foreach ($workers as [$process, $pipes]) {
            $outcomes[] = trim(fgets($pipes[1]) ?: '');
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0)->and($error)->toBe('');
        }
        if ($kind === 'generate') {
            expect($outcomes)->toBe(['DONE', 'DONE'])->and(Serial::count())->toBe(40)
                ->and(Serial::distinct()->count('serial'))->toBe(40)->and(Products::count())->toBe(0);
        } else {
            expect(array_count_values($outcomes)['DONE'] ?? 0)->toBe(1)
                ->and(Products::count())->toBe(1)->and(DB::table('refill_entries')->count())->toBe(1)
                ->and(Serial::first()->products_id)->toBe(Products::first()->id);
            foreach ($outcomes as $outcome) {
                expect($outcome)->toBeIn(['DONE', 'REJECTED', 'BLOCKED']);
            }
        }
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        DB::disconnect('sqlite');
        if (is_file($file)) {
            unlink($file); // Only the exact temporary test file created above.
        }
    }
})->with(['generate', 'scan']);
