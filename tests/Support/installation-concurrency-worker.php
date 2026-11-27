<?php

use App\Models\Installation;
use App\Models\User;
use App\Services\InstallationProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

try {
    $data = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $file = realpath($data['file']);
    if (! $file || ! str_starts_with(basename($file), 'proteng-installation-test-')
        || realpath(dirname($file)) !== realpath(sys_get_temp_dir())) {
        throw new RuntimeException('Only the isolated temporary installation database is allowed.');
    }

    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $app->instance('env', 'testing');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file,
        'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
        'database.connections.sqlite.busy_timeout' => 5000, 'cache.default' => 'array',
        'permission.cache.store' => 'array', 'production.catalog_ids' => []]);
    $installation = Installation::findOrFail($data['installation']);
    $actor = User::findOrFail(1);
    echo "READY\n";
    flush();
    if (trim(fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing test barrier.');
    }
    try {
        $app->make(InstallationProductService::class)->register($installation, $data['serial'], $actor);
        echo "DONE\n";
    } catch (ValidationException) {
        echo "REJECTED\n";
    } catch (QueryException $exception) {
        if (! str_contains($exception->getMessage(), 'database is locked')) {
            throw $exception;
        }
        echo "BLOCKED\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class."\n");
    exit(1);
}
