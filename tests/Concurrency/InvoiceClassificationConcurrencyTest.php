<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
it('serializes two invoice classifications on an empty isolated MySQL database', function () {
    $database = getenv('INVOICE_TEST_MYSQL_DATABASE');
    if (! $database) {
        $this->markTestSkipped('Requires an empty isolated INVOICE_TEST_MYSQL_DATABASE.');
    }
    expect((bool) preg_match('/^proteng_invoice_test_[a-zA-Z0-9_]+$/', $database))->toBeTrue();
    $connection = ['driver' => 'mysql', 'host' => getenv('INVOICE_TEST_MYSQL_HOST') ?: '127.0.0.1', 'port' => getenv('INVOICE_TEST_MYSQL_PORT') ?: '3306',
        'database' => $database, 'username' => getenv('INVOICE_TEST_MYSQL_USERNAME') ?: '', 'password' => getenv('INVOICE_TEST_MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
    config(['database.default' => 'invoice_test', 'database.connections.invoice_test' => $connection, 'production.catalog_ids' => [], 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    DB::purge('invoice_test');
    expect(DB::connection()->getDatabaseName())->toBe($database)->and(Schema::getTables())->toBe([]);
    createTraceabilityFixtures();
    invoiceIntegrationUp();
    Role::create(['name' => 'Manager', 'guard_name' => 'web']);
    User::find(1)->assignRole('Manager');
    $customer = Customer::create(['quickbooks_id' => '10', 'display_name' => 'Buyer']);
    DB::table('asset')->where('id', 31)->update(['quickbooks_id' => '100']);
    $invoice = Invoice::create(['quickbooks_id' => '500', 'customer_id' => $customer->id, 'total' => 100, 'balance' => 100, 'status' => 'open', 'sync_status' => 'synced', 'last_synced_at' => now()]);
    $invoice->lines()->create(['line_number' => 1, 'detail_type' => 'SalesItemLineDetail', 'item_type' => 'Inventory', 'quickbooks_item_id' => '100', 'asset_id' => 31, 'quantity' => 2, 'amount' => 100, 'raw_payload' => []]);
    $workers = [];
    $outcomes = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/invoice-concurrency-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start worker.');
            }
            $workers[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(['connection' => $connection, 'invoice' => $invoice->id], JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
        }
        foreach ($workers as [$process,$pipes]) {
            stream_set_timeout($pipes[1], 30);
            expect(trim(fgets($pipes[1]) ?: ''))->toBe('READY');
        }
        foreach ($workers as [$process,$pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }
        foreach ($workers as [$process,$pipes]) {
            $outcomes[] = trim(fgets($pipes[1]) ?: '');
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0)->and($errors)->toBe('');
        }
        sort($outcomes);
        expect($outcomes)->toBe(['DONE', 'REJECTED']);
        expect(WorkOrder::count())->toBe(1)->and(DB::table('wo_ass')->count())->toBe(1)->and($invoice->fresh()->operation_status)->toBe('created');
    } finally {
        foreach ($workers as [$process,$pipes]) {
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
        DB::disconnect('invoice_test');
    }
});
