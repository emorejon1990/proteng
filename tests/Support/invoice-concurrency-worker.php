<?php

use App\Models\User;
use App\Services\InvoiceClassificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
try {
    $payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    $connection = $payload['connection'];
    $sqlite = $connection['driver'] === 'sqlite';
    $file = $sqlite ? realpath($connection['database']) : false;
    $isolated = $sqlite
        ? $file && str_starts_with(basename($file), 'proteng-invoice-test-') && dirname($file) === realpath(sys_get_temp_dir())
        : $connection['driver'] === 'mysql' && preg_match('/^proteng_invoice_test_[a-zA-Z0-9_]+$/', $connection['database'] ?? '');
    if (! $isolated) {
        throw new RuntimeException('An isolated invoice test database is required.');
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'invoice_test', 'database.connections.invoice_test' => $payload['connection'], 'production.catalog_ids' => [], 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    $actor = User::findOrFail(1);
    echo "READY\n";
    flush();
    if (trim(fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing test barrier.');
    }
    try {
        $service = $app->make(InvoiceClassificationService::class);
        if (isset($payload['order'])) {
            $service->assignToInstallation($payload['order'], $payload['invoice'], $actor);
        } else {
            $service->classify($payload['invoice'], 'distribution', $actor);
        }
        echo "DONE\n";
    } catch (ValidationException) {
        echo "REJECTED\n";
    } catch (\Illuminate\Database\QueryException $exception) {
        if (! $sqlite || ! str_contains($exception->getMessage(), 'database is locked')) {
            throw $exception;
        }
        echo "BLOCKED\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
