<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InvoiceSelectiveSyncService
{
    public const IDS = ['1982', '11241', '11990'];

    private array $captureStatus = [];

    private ?string $activeId = null;

    public function __construct(private QuickBooksReadGateway $gateway, private InvoiceSelectiveSandbox $sandbox, private InvoiceSelectiveVerification $verification) {}

    public function dryRun(User $actor): array
    {
        $this->authorize($actor);

        return app(QuickBooksSyncLock::class)->run(function () use ($actor) {
            $report = $this->report('dry_run');
            try {
                $input = $this->capture();
                $report['company'] = ['realm_id' => $input['realm'], 'environment' => $input['environment']];
                $results = $this->sandbox->run($input);
                foreach (self::IDS as $id) {
                    $report['invoices'][$id] = $results[$id];
                    $report['invoices'][$id]['seconds'] += $input['api_seconds'][$id];
                }
                $report['passed'] = count($results) === 3 && collect($results)->every(fn ($result) => ($result['passed'] ?? false) === true);
                if ($report['passed']) {
                    $ticket = Str::random(64);
                    Cache::put($this->ticketKey($actor, $ticket), Crypt::encryptString(json_encode([
                        'actor_id' => $actor->id, 'fingerprint' => $this->fingerprint($input),
                        'states' => $input['states'], 'realm' => $input['realm'], 'environment' => $input['environment'],
                        'input' => $input,
                    ], JSON_THROW_ON_ERROR)), now()->addMinutes(15));

                    return ['report' => $report, 'ticket' => $ticket];
                }
            } catch (\Throwable $e) {
                $report['invoices'] = $this->captureStatus ?: $report['invoices'];
                if ($this->activeId !== null) {
                    $report['invoices'][$this->activeId]['errors'][] = 'Read or preparation failed; selective test stopped.';
                }
                $report['errors'][] = $e instanceof InvoiceDataException ? $e->getMessage() : 'Dry Run stopped. Check the configured company, connection and isolated PHP/SQLite process.';
            }

            return ['report' => $report, 'ticket' => null];
        });
    }

    public function apply(User $actor, string $ticket, bool $confirmed): array
    {
        $this->authorize($actor);
        abort_unless($confirmed, 422, 'Apply requires a separate explicit confirmation.');

        return app(QuickBooksSyncLock::class)->run(function () use ($actor, $ticket) {
            $key = $this->ticketKey($actor, $ticket);
            $approval = Cache::pull($key); // Single-use, consumed even when the source changes or Apply fails.
            abort_unless(is_string($approval), 422, 'A successful, unexpired Dry Run is required.');
            $approval = json_decode(Crypt::decryptString($approval), true, 512, JSON_THROW_ON_ERROR);
            abort_unless($approval['actor_id'] === $actor->id, 403);
            $report = $this->report('apply');
            try {
                $input = $this->capture();
                $report['company'] = ['realm_id' => $input['realm'], 'environment' => $input['environment']];
                if (! hash_equals($approval['fingerprint'], $this->fingerprint($input))) {
                    $report['invoices'] = $this->captureStatus;
                    unset($approval['input']['api_seconds'], $input['api_seconds']);
                    $report['errors'][] = 'Dry Run is stale. Changed fields: '.implode(', ', $this->differences($approval['input'], $input)).'. Run Dry Run again.';

                    return $report;
                }
                $gateway = new QuickBooksCapturedReadGateway($input['realm'], $input['responses']);
                $importer = new InvoiceSyncService($gateway, app(CustomerSyncService::class), app(InvoiceRequirements::class));
                foreach (self::IDS as $id) {
                    $started = microtime(true);
                    $result = null;
                    $before = null;
                    try {
                        $before = $this->verification->snapshot($id);
                        $result = DB::transaction(function () use ($id, $input, $approval, $importer, &$result) {
                            app(QuickBooksSyncLock::class)->assertOwned();
                            $before = $this->verification->snapshot($id, true);
                            if ($before !== $approval['states'][$id]) {
                                throw new \RuntimeException('Local invoice changed after Dry Run.');
                            }
                            $invoice = $importer->import($input['responses']['Invoice'][$id], $input['realm']);
                            $result = ['consulted' => true] + $this->verification->verify($input['responses']['Invoice'][$id], $before, $invoice, $input['realm']);
                            if (! $result['passed']) {
                                throw new \RuntimeException('Mandatory integrity checks failed.');
                            }
                            app(QuickBooksSyncLock::class)->assertOwned();

                            return $result;
                        });
                    } catch (\Throwable $e) {
                        $result ??= ['consulted' => true, 'passed' => false, 'checks' => [], 'errors' => ['Import stopped. Review the rollback verification.']];
                        $result['passed'] = false;
                        $restored = null;
                        try {
                            $restored = $this->verification->snapshot($id);
                        } catch (\Throwable $verificationError) {
                            $result['errors'][] = 'Database state could not be verified after failure. Manual review is required.';
                        }
                        $preserved = $before !== null && $before === $restored;
                        $result['final_state'] = $preserved ? 'rolled_back' : 'unverified_stop';
                        $result['checks']['failed_import_preserved_data'] = $preserved;
                        $result['attempted_after'] = $result['after'] ?? null;
                        $result['after'] = $restored === null ? null : $this->verification->summary($restored);
                        $report['invoices'][$id] = $result + ['seconds' => round(microtime(true) - $started + $input['api_seconds'][$id], 3)];
                        $report['errors'][] = 'Apply stopped at invoice '.$id.'. No subsequent invoices were modified.';

                        return $report;
                    }
                    $report['invoices'][$id] = $result + ['seconds' => round(microtime(true) - $started + $input['api_seconds'][$id], 3)];
                }
                $report['passed'] = true;
            } catch (\Throwable $e) {
                $report['invoices'] = $this->captureStatus ?: $report['invoices'];
                if ($this->activeId !== null) {
                    $report['invoices'][$this->activeId]['errors'][] = 'Read or preparation failed; Apply stopped before import.';
                }
                $report['errors'][] = 'Apply stopped before import. The source, company, local state or connection may have changed; run Dry Run again.';
            }

            return $report;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
    }

    private function ticketKey(User $actor, string $ticket): string
    {
        return 'quickbooks.selective.approval.'.$actor->id.'.'.hash('sha256', $ticket);
    }

    private function report(string $mode): array
    {
        return ['mode' => $mode, 'passed' => false, 'created_at' => now()->toIso8601String(), 'errors' => [],
            'invoices' => array_fill_keys(self::IDS, ['consulted' => false, 'passed' => false, 'change' => 'not_processed',
                'line_count' => null, 'total' => null, 'final_state' => 'not_processed', 'seconds' => 0, 'errors' => [], 'checks' => []])];
    }

    private function capture(): array
    {
        $this->captureStatus = $this->report('dry_run')['invoices'];
        $this->activeId = null;
        $realm = $this->gateway->realm();
        $environment = (string) config('services.quickbooks.env');
        if (Invoice::whereNotNull('realm_id')->where('realm_id', '!=', $realm)->exists()) {
            throw new \RuntimeException('Existing invoice company differs from the connected company.');
        }
        $input = ['realm' => $realm, 'environment' => $environment, 'responses' => ['Invoice' => [], 'Customer' => [], 'Item' => []], 'api_seconds' => []];
        foreach (self::IDS as $id) {
            $started = microtime(true);
            $this->activeId = $id;
            $this->captureStatus[$id]['queried'] = true;
            $this->assertConnection($realm, $environment);
            $payload = $this->gateway->find('Invoice', $id);
            if ((string) ($payload['Id'] ?? '') !== $id) {
                throw new \RuntimeException('Unexpected invoice identity.');
            }
            $input['responses']['Invoice'][$id] = $payload;
            $this->captureStatus[$id]['consulted'] = true;
            $this->captureStatus[$id]['line_count'] = is_array($payload['Line'] ?? null) ? count($payload['Line']) : null;
            $this->captureStatus[$id]['total'] = is_numeric($payload['TotalAmt'] ?? null) ? number_format((float) $payload['TotalAmt'], 2, '.', '') : null;
            try {
                $customer = QuickBooksReadGateway::entityRef($payload['CustomerRef'] ?? null, ['entity' => 'Customer']);
                if (! DB::table('customers')->where('quickbooks_id', $customer)->exists() && ! isset($input['responses']['Customer'][$customer])) {
                    $this->assertConnection($realm, $environment);
                    $input['responses']['Customer'][$customer] = $this->gateway->find('Customer', $customer);
                }
                $local = Invoice::where('quickbooks_id', $id)->first();
                $stored = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                if (! $local || ! InvoiceSyncService::payloadMatches($local->raw_payload, $stored)) {
                    foreach ($payload['Line'] ?? [] as $line) {
                        if (($line['DetailType'] ?? null) !== 'SalesItemLineDetail' || ! is_array($line['SalesItemLineDetail'] ?? null)) {
                            continue;
                        }
                        $ref = $line['SalesItemLineDetail']['ItemRef'] ?? null;
                        if ($ref === null || QuickBooksReadGateway::ref($ref) === 'SHIPPING_ITEM_ID') {
                            continue;
                        }
                        $item = QuickBooksReadGateway::entityRef($ref, ['entity' => 'Item']);
                        if (! isset($input['responses']['Item'][$item])) {
                            $this->assertConnection($realm, $environment);
                            $input['responses']['Item'][$item] = $this->gateway->find('Item', $item);
                        }
                    }
                }
            } catch (InvoiceDataException $e) {
                // The shared importer will report invalid invoice references in the sandbox.
            }
            $input['api_seconds'][$id] = round(microtime(true) - $started, 3);
            $this->captureStatus[$id]['seconds'] = $input['api_seconds'][$id];
        }
        $this->assertConnection($realm, $environment);
        [$input['local'], $input['states']] = DB::transaction(fn () => $this->localState($input));
        $this->activeId = null;

        return $input;
    }

    private function assertConnection(string $realm, string $environment): void
    {
        app(QuickBooksSyncLock::class)->assertOwned();
        if ($this->gateway->realm() !== $realm || (string) config('services.quickbooks.env') !== $environment) {
            throw new \RuntimeException('QuickBooks connection changed during selective test.');
        }
    }

    private function localState(array $input): array
    {
        $states = [];
        foreach (self::IDS as $id) {
            $states[$id] = $this->verification->snapshot($id);
        }
        $invoices = array_values(array_filter(array_column($states, 'invoice')));
        $lines = array_merge(...array_column($states, 'lines'));
        $orders = array_merge(...array_column($states, 'work_orders'));
        $quantities = array_merge(...array_column($states, 'quantities'));
        $installations = array_merge(...array_column($states, 'installations'));
        $customerIds = array_filter(array_column($invoices, 'customer_id'));
        $customerRefs = [];
        foreach ($input['responses']['Invoice'] as $payload) {
            try {
                $customerRefs[] = QuickBooksReadGateway::entityRef($payload['CustomerRef'] ?? null);
            } catch (InvoiceDataException $e) {
                // Malformed input is validated by the importer, not guessed here.
            }
        }
        $assetIds = array_filter(array_merge(array_column($lines, 'asset_id'), array_column($quantities, 'asset_id')));
        $rows = [
            'invoices' => $invoices, 'invoice_lines' => $lines, 'invoice_events' => [],
            'work_orders' => $orders, 'wo_ass' => $quantities, 'installations' => $installations,
            'customers' => DB::table('customers')->whereIn('id', $customerIds)->orWhereIn('quickbooks_id', $customerRefs)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'asset' => DB::table('asset')->whereIn('id', $assetIds)->orWhereIn('quickbooks_id', array_keys($input['responses']['Item']))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
        $local = [];
        foreach (InvoiceSelectiveSandbox::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException('Required selective test schema is unavailable.');
            }
            $local[$table] = ['columns' => Schema::getColumns($table), 'indexes' => Schema::getIndexes($table), 'rows' => $rows[$table]];
        }

        return [$local, $states];
    }

    private function fingerprint(array $input): string
    {
        unset($input['api_seconds']);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function differences(mixed $before, mixed $after, string $path = ''): array
    {
        if ($before === $after) {
            return [];
        }
        if (! is_array($before) || ! is_array($after)) {
            return [$path];
        }
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $changes = array_merge($changes, $this->differences($before[$key] ?? null, $after[$key] ?? null, ltrim($path.'.'.$key, '.')));
            if (count($changes) >= 100) {
                return array_slice($changes, 0, 100);
            }
        }

        return $changes;
    }
}
