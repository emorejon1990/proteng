<?php

use App\Models\ProductCycle;
use App\Models\User;
use App\Services\ReviewWorkOrderService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

try {
    $payload = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/^proteng_review_test_[a-zA-Z0-9_]+$/', $payload['connection']['database'] ?? '')) {
        throw new RuntimeException('An isolated Review test database is required.');
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'review_test', 'database.connections.review_test' => $payload['connection'],
        'production.catalog_ids' => [], 'cache.default' => 'array', 'permission.cache.store' => 'array',
    ]);
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
        throw new RuntimeException('Missing test start barrier.');
    }
    foreach ($payload['cycles'] as $id) {
        $app->make(ReviewWorkOrderService::class)->enqueue(ProductCycle::findOrFail($id), $actor);
    }
    echo "DONE\n";
} catch (Throwable $exception) {
    // Do not print connection parameters or credentials from the parent process.
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
}
