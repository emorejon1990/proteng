<?php

use App\Models\User;
use App\Services\DistributionSelectionService;
use App\Services\ProductionFillingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

try {
    $payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/^proteng_production_test_[a-zA-Z0-9_]+$/', $payload['connection']['database'] ?? '')) {
        throw new RuntimeException('An isolated production test database is required.');
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'production_test', 'database.connections.production_test' => $payload['connection'],
        'production.catalog_ids' => [], 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    $actor = new class extends User
    {
        public function hasRole($roles, ?string $guard = null): bool
        {
            return true;
        }
    };
    $actor->forceFill(['id' => 1]);
    echo "READY\n";
    flush();
    if (trim(fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing test barrier.');
    }
    try {
        $action = $payload['action'];
        match ($action['kind']) {
            'reserve' => $app->make(DistributionSelectionService::class)->reserve($action['order'], $action['asset'], $action['serial'], $actor),
            'confirm' => $app->make(DistributionSelectionService::class)->confirm($action['order'], $actor),
            'fill' => $app->make(ProductionFillingService::class)->fill($action['order'], $action['product'], $action['serial'], $action['weight'], $actor),
            default => throw new RuntimeException('Unknown concurrency operation.'),
        };
        echo "DONE\n";
    } catch (ValidationException) {
        echo "REJECTED\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
}
