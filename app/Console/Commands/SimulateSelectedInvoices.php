<?php

namespace App\Console\Commands;

use App\Services\InvoiceSelectiveSandbox;
use App\Services\InvoiceSelectiveSyncService;
use App\Services\QuickBooksReadGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

/** Internal subprocess entry point. No API access or production import option. */
class SimulateSelectedInvoices extends Command
{
    protected $signature = 'quickbooks:simulate-selected {input} {output}';

    protected $description = 'Internal isolated SQLite simulation of captured selective invoice responses';

    public function handle(InvoiceSelectiveSandbox $sandbox): int
    {
        try {
            foreach (['input' => 'input.enc', 'output' => 'report.json'] as $argument => $fileName) {
                $path = $this->argument($argument);
                if (! is_file($path) || is_link($path) || is_link(dirname($path)) || basename($path) !== $fileName
                    || ! preg_match('/^qb-selective-[a-f0-9]{32}$/D', basename(dirname($path)))
                    || realpath(dirname(dirname($path))) !== realpath(sys_get_temp_dir())) {
                    throw new \RuntimeException('Only private simulation temporary files are accepted.');
                }
            }
            $input = json_decode(Crypt::decryptString(file_get_contents($this->argument('input'))), true, 512, JSON_THROW_ON_ERROR);
            $ids = $input['ids'] ?? [];
            if (! is_array($ids) || ! $ids || count($ids) > InvoiceSelectiveSyncService::SAMPLE_SIZE
                || count(array_unique($ids)) !== count($ids)
                || array_map('strval', array_keys($input['responses']['Invoice'])) !== $ids) {
                throw new \RuntimeException('Unexpected invoice scope.');
            }
            foreach ($ids as $id) {
                QuickBooksReadGateway::entityRef($id);
            }
            $results = $sandbox->simulate($input);
            file_put_contents($this->argument('output'), json_encode($results, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Isolated simulation failed. No existing database was used for import.');

            return self::FAILURE;
        }
    }
}
