<?php

use App\Models\User;
use App\Services\ExistingProductsRefillService;
use App\Services\SerialGenerationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

try {
    $input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    $file = realpath($input['file']);
    if (! $file || ! str_starts_with(basename($file), 'proteng-refill-test-')
        || realpath(dirname($file)) !== realpath(sys_get_temp_dir())) {
        throw new RuntimeException('Only an isolated temporary refill test database is allowed.');
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $app->instance('env', 'testing');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file,
        'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
        'database.connections.sqlite.busy_timeout' => 5000, 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    $actor = User::findOrFail(1);
    echo "READY\n";
    flush();
    if (trim(fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing test barrier.');
    }
    try {
        if ($input['kind'] === 'generate') {
            $app->make(SerialGenerationService::class)->generate(20, $actor);
        } else {
            $app->make(ExistingProductsRefillService::class)->scan($input['data'], $input['serial'], null, $actor);
        }
        echo "DONE\n";
    } catch (ValidationException) {
        echo "REJECTED\n";
    } catch (QueryException $e) {
        // SQLite has database-level write serialization, not InnoDB row locks.
        if (! str_contains($e->getMessage(), 'database is locked')) {
            throw $e;
        }
        echo "BLOCKED\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(1);
}
