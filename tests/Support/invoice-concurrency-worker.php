<?php

use App\Models\User;
use App\Services\InvoiceClassificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
try {
    $payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/^proteng_invoice_test_[a-zA-Z0-9_]+$/', $payload['connection']['database'] ?? '')) {
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
        $app->make(InvoiceClassificationService::class)->classify($payload['invoice'], 'distribution', $actor);
        echo "DONE\n";
    } catch (ValidationException) {
        echo "REJECTED\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
