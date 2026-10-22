<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use QuickBooksOnline\API\DataService\DataService;
use Symfony\Component\Process\Process;

class InvoiceSelectiveSandbox
{
    public const TABLES = ['customers', 'asset', 'work_orders', 'wo_ass', 'installations', 'invoices', 'invoice_lines', 'invoice_events'];

    public function run(array $input): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qb-selective-'.bin2hex(random_bytes(16));
        $inputPath = $directory.DIRECTORY_SEPARATOR.'input.enc';
        $outputPath = $directory.DIRECTORY_SEPARATOR.'report.json';
        try {
            if (! mkdir($directory, 0700)) {
                throw new \RuntimeException('Unable to prepare private simulation files.');
            }
            file_put_contents($inputPath, Crypt::encryptString(json_encode($input, JSON_THROW_ON_ERROR)));
            file_put_contents($outputPath, '');
            chmod($inputPath, 0600);
            chmod($outputPath, 0600);
            $php = PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
            $process = new Process([$php, base_path('artisan'), 'quickbooks:simulate-selected', $inputPath, $outputPath], base_path(), [
                // Disable inherited cached DB configuration before Laravel bootstraps in the child.
                'APP_CONFIG_CACHE' => $directory.DIRECTORY_SEPARATOR.'unused-config.php', 'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => 'null',
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_USERNAME' => '', 'DB_PASSWORD' => '', 'DB_SOCKET' => '',
                'REDIS_URL' => 'null', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '1',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
                'LOG_CHANNEL' => 'null', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
            ]);
            $process->setTimeout(120)->run();
            if (! $process->isSuccessful()) {
                // CLI errors can contain bindings; never pass stdout/stderr to the UI or report.
                throw new \RuntimeException('Isolated simulation failed. Check PHP CLI and PDO SQLite availability.');
            }

            return json_decode(file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach ([$inputPath, $outputPath] as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** Called only by the isolated CLI process; no existing database is retained. */
    public function simulate(array $input): array
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        config(['database.connections' => ['selective_memory' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]], 'database.default' => 'selective_memory', 'cache.default' => 'array', 'mail.default' => 'array', 'session.driver' => 'array']);
        DB::setDefaultConnection('selective_memory');
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Simulation requires an isolated in-memory database.');
        }
        Http::preventStrayRequests();
        $this->copySchema($input['local']);
        $gateway = new QuickBooksCapturedReadGateway($input['realm'], $input['responses']);
        $qb = new class extends QuickBooksService
        {
            public function ds(?string $expectedRealm = null): DataService
            {
                throw new \LogicException('Network access is prohibited in simulation.');
            }
        };
        $importer = new InvoiceSyncService($gateway, new CustomerSyncService($qb), new InvoiceRequirements);
        $verifier = new InvoiceSelectiveVerification;

        return Model::withoutEvents(function () use ($input, $importer, $verifier) {
            $results = [];
            foreach (InvoiceSelectiveSyncService::IDS as $id) {
                $started = microtime(true);
                $before = $verifier->snapshot($id);
                try {
                    $payload = $input['responses']['Invoice'][$id];
                    $filters = $input['filters'][$id];
                    if (! $before['invoice'] && $filters['result'] !== 'candidate') {
                        $results[$id] = ['consulted' => true] + $verifier->filtered($payload, $before, $filters);
                    } else {
                        $invoice = $importer->import($payload, $input['realm'], $filters);
                        $results[$id] = ['consulted' => true] + $verifier->verify($payload, $before, $invoice, $input['realm'], $filters);
                    }
                } catch (\Throwable $e) {
                    $results[$id] = ['consulted' => true, 'passed' => false, 'errors' => [
                        $e instanceof InvoiceDataException ? $e->getMessage() : 'Isolated import failed.',
                    ], 'import_diagnostics' => $e instanceof InvoiceDataException ? [['message' => $e->getMessage()] + $e->context] : [],
                        'filters' => $input['filters'][$id] ?? null, 'balance' => $payload['Balance'] ?? null, 'selection_result' => 'review',
                        'operational_diagnostics' => [], 'operational_scope' => 'not_evaluated',
                        'checks' => ['failed_import_preserved_data' => $before === $verifier->snapshot($id)], 'final_state' => 'failed'];
                }
                $results[$id]['seconds'] = round(microtime(true) - $started, 3);
            }

            return $results;
        });
    }

    private function copySchema(array $local): void
    {
        foreach (self::TABLES as $name) {
            $definition = $local[$name];
            Schema::create($name, function (Blueprint $table) use ($definition) {
                foreach ($definition['columns'] as $column) {
                    $name = $column['name'];
                    if ($name === 'id') {
                        $table->id();

                        continue;
                    }
                    $type = strtolower($column['type_name']);
                    $field = match (true) {
                        str_contains($type, 'int') => $table->bigInteger($name),
                        in_array($type, ['real', 'float', 'double', 'double precision'], true) => $table->double($name),
                        in_array($type, ['decimal', 'numeric'], true) => $table->decimal($name, 18, 6),
                        default => $table->text($name),
                    };
                    $field->nullable($column['nullable']);
                    if ($column['default'] !== null) {
                        $default = $column['default'];
                        if (is_string($default) && str_starts_with($default, "'") && str_ends_with($default, "'")) {
                            $default = str_replace("''", "'", substr($default, 1, -1));
                        }
                        $field->default($default);
                    }
                }
                foreach ($definition['indexes'] as $index) {
                    if ($index['unique'] && ! $index['primary']) {
                        $table->unique($index['columns']);
                    }
                }
            });
        }
        foreach (self::TABLES as $name) {
            foreach ($local[$name]['rows'] as $row) {
                DB::table($name)->insert($row);
            }
        }
    }
}
