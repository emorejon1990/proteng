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
    public const SAMPLE_SIZE = 50;

    public function __construct(private QuickBooksReadGateway $gateway, private InvoiceSelectiveSandbox $sandbox, private InvoiceSelectiveVerification $verification) {}

    /** One random draw; subsequent requests never redraw positions or replace failed invoices. */
    public function dryRun(User $actor): array
    {
        $this->authorize($actor);

        return app(QuickBooksSyncLock::class)->run(function () use ($actor) {
            $realm = $this->gateway->realm();
            $environment = (string) config('services.quickbooks.env');
            if (Invoice::whereNotNull('realm_id')->where('realm_id', '!=', $realm)->exists()) {
                throw new \RuntimeException('Existing invoice company differs from the connected company.');
            }
            $count = $this->gateway->invoiceCount(true);
            $positions = [];
            // Floyd sampling: uniform sampling without replacement, O(50) memory.
            for ($n = $count - min(self::SAMPLE_SIZE, $count) + 1; $n <= $count; $n++) {
                $pick = random_int(1, $n);
                $positions[isset($positions[$pick]) ? $n : $pick] = true;
            }
            $positions = array_keys($positions);
            shuffle($positions);
            $run = Str::random(64);
            $state = ['actor_id' => $actor->id, 'realm' => $realm, 'environment' => $environment,
                'offset' => 0, 'pending' => null, 'items' => [], 'failed_items' => [], 'customers' => [],
                'report' => ['mode' => 'dry_run', 'status' => $count ? 'running' : 'completed',
                    'created_at' => now()->toIso8601String(), 'passed' => $count === 0,
                    'company' => ['realm_id' => $realm, 'environment' => $environment],
                    'available_invoices' => $count, 'pending_invoices_found' => $count, 'selection_filter' => 'Balance > 0', 'requested_count' => self::SAMPLE_SIZE,
                    'selected_positions' => $positions, 'selected_ids' => [], 'invoices' => [], 'errors' => [],
                    'notice' => $count < self::SAMPLE_SIZE ? 'QuickBooks has '.$count.' invoices with a pending balance; all available pending invoices are selected.' : null]];
            $this->save($run, $state);

            return ['report' => $state['report'], 'run_id' => $run, 'ticket' => null];
        });
    }

    /** At most one remote read per request, followed by a later isolated simulation request. */
    public function advanceDryRun(User $actor, string $run): array
    {
        $this->authorize($actor);
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $run), 422);

        return Cache::lock($this->key($run).'.lock', 180)->block(1, function () use ($actor, $run) {
            $encrypted = Cache::get($this->key($run));
            abort_unless(is_string($encrypted), 422, 'Dry Run expired. Start a new sample.');
            $state = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            abort_unless($state['actor_id'] === $actor->id, 403);
            if ($state['report']['status'] !== 'running') {
                return $state['report'];
            }
            try {
                app(QuickBooksSyncLock::class)->run(function () use (&$state) {
                    $this->assertConnection($state['realm'], $state['environment']);
                    $this->step($state);
                    $this->assertConnection($state['realm'], $state['environment']);
                });
            } catch (\Throwable $e) {
                $state['report']['status'] = 'stopped';
                $state['report']['passed'] = false;
                $state['report']['errors'][] = 'Dry Run stopped safely. Check company, connection, cache and simulation availability. Completed results remain available.';
            }
            $this->save($run, $state);

            return $state['report'];
        });
    }

    public function apply(User $actor, string $ticket, bool $confirmed): array
    {
        $this->authorize($actor);
        abort(403, 'Apply is disabled for random invoice samples.');
    }

    private function step(array &$state): void
    {
        $started = microtime(true);
        $position = $state['report']['selected_positions'][$state['offset']];
        $id = $state['pending']['id'] ?? 'position:'.$position;
        try {
            if ($state['pending'] === null) {
                $page = $this->gateway->page('Invoice', $position, 1, true);
                if (count($page) !== 1) {
                    throw new InvoiceDataException('Selected invoice is no longer available.');
                }
                $payload = $page[0];
                $id = QuickBooksReadGateway::entityRef($payload['Id'] ?? null);
                if (in_array($id, $state['report']['selected_ids'], true)) {
                    // Concurrent additions/deletions can shift offsets. Do not bias the sample by redrawing.
                    $state['report']['status'] = 'stopped';
                    $state['report']['errors'][] = 'Invoice listing changed during sampling: duplicate ID encountered. No replacement was drawn.';

                    return;
                }
                $state['report']['selected_ids'][] = $id;
                $state['report']['invoices'][$id] = ['invoice_number' => isset($payload['DocNumber']) ? (string) $payload['DocNumber'] : null,
                    'consulted' => true, 'passed' => false, 'change' => 'pending',
                    'line_count' => count($payload['Line'] ?? []), 'total' => $payload['TotalAmt'] ?? null,
                    'balance' => $payload['Balance'] ?? null, 'final_state' => 'pending', 'seconds' => 0, 'errors' => []];
                $state['pending'] = ['id' => $id, 'payload' => $payload, 'queue' => [], 'refreshed' => false];

                return;
            }
            $pending = &$state['pending'];
            if (empty($pending['refreshed'])) {
                // Re-read the selected ID, not its potentially shifted position, before reading Items.
                $payload = $this->gateway->find('Invoice', $id);
                if (QuickBooksReadGateway::entityRef($payload['Id'] ?? null) !== $id) {
                    throw new InvoiceDataException('Selected invoice identity changed.');
                }
                $pending['payload'] = $payload;
                $pending['refreshed'] = true;
                $pending['queue'] = [];
                foreach ($payload['Line'] ?? [] as $line) {
                    if (($line['DetailType'] ?? null) === 'SalesItemLineDetail') {
                        try {
                            $ref = QuickBooksReadGateway::ref($line['SalesItemLineDetail']['ItemRef'] ?? null);
                            if ($ref !== 'SHIPPING_ITEM_ID') {
                                $pending['queue'][QuickBooksReadGateway::entityRef($ref)] = 0;
                            }
                        } catch (InvoiceDataException $e) {
                            // Existing filter diagnostics handle invalid references.
                        }
                    }
                }
                $row = &$state['report']['invoices'][$id];
                $row['invoice_number'] = isset($payload['DocNumber']) ? (string) $payload['DocNumber'] : null;
                $row['total'] = $payload['TotalAmt'] ?? null;
                $row['balance'] = $payload['Balance'] ?? null;
                $row['line_count'] = count($payload['Line'] ?? []);

                return;
            }
            $payload = $pending['payload'];
            // Evaluate payment alone first; excluded invoices do not need Item/category reads.
            $payment = app(InvoiceRequirements::class)->filters(array_replace($payload, ['Line' => []]), fn () => []);
            if ($payment['payment_filter'] === 'excluded') {
                $payment['category_filter'] = 'not_evaluated';
                $payment['result'] = 'excluded';
                $before = $this->verification->snapshot($id);
                $result = ['invoice_number' => isset($payload['DocNumber']) ? (string) $payload['DocNumber'] : null,
                    'consulted' => true] + $this->verification->filtered($payload, $before, $payment);
                $result['seconds'] = $state['report']['invoices'][$id]['seconds'];
                $state['report']['invoices'][$id] = $result;
                $state['pending'] = null;
                $state['offset']++;

                return;
            }
            // Expand cached parent chains locally; fetch only the first unresolved Item/category.
            do {
                $expanded = false;
                foreach ($pending['queue'] as $ref => $depth) {
                    if (isset($state['failed_items'][$ref])) {
                        continue;
                    }
                    if (! isset($state['items'][$ref])) {
                        try {
                            $state['items'][$ref] = $this->gateway->find('Item', (string) $ref);
                            if ((string) ($state['items'][$ref]['Id'] ?? '') !== (string) $ref) {
                                throw new InvoiceDataException('Item response identity mismatch.');
                            }
                        } catch (\Throwable $e) {
                            unset($state['items'][$ref]);
                            $state['failed_items'][$ref] = true;
                            $state['report']['invoices'][$id]['technical_error'] = true;
                        }

                        return;
                    }
                    $parent = $state['items'][$ref]['ParentRef'] ?? null;
                    if ($parent !== null && $depth < 20) {
                        try {
                            $parent = QuickBooksReadGateway::entityRef($parent);
                            if (! array_key_exists($parent, $pending['queue'])) {
                                $pending['queue'][$parent] = $depth + 1;
                                $expanded = true;
                            }
                        } catch (InvoiceDataException $e) {
                            // Existing category resolver reports malformed/cyclic references.
                        }
                    }
                }
            } while ($expanded);
            $payload = $pending['payload'];
            $responses = ['Invoice' => [$id => $payload], 'Item' => $state['items'], 'Customer' => $state['customers']];
            $gateway = new QuickBooksCapturedReadGateway($state['realm'], $responses);
            $filters = app(InvoiceRequirements::class)->filters($payload, fn ($ref) => $gateway->find('Item', $ref));
            if ($filters['result'] === 'candidate' || Invoice::where('quickbooks_id', $id)->exists()) {
                try {
                    $customer = QuickBooksReadGateway::entityRef($payload['CustomerRef'] ?? null);
                    if (! isset($state['customers'][$customer]) && ! DB::table('customers')->where('quickbooks_id', $customer)->exists()) {
                        $state['customers'][$customer] = $this->gateway->find('Customer', $customer);

                        return;
                    }
                } catch (InvoiceDataException $e) {
                    // Importer will report invalid customer references in the sandbox.
                }
            }
            $input = ['ids' => [$id], 'realm' => $state['realm'], 'environment' => $state['environment'],
                'responses' => $responses, 'filters' => [$id => $filters]];
            [$input['local'], $input['states']] = DB::transaction(fn () => $this->localState($input, $state['schema'] ?? []));
            $state['schema'] ??= array_map(fn ($table) => ['columns' => $table['columns'], 'indexes' => $table['indexes']], $input['local']);
            $result = $this->sandbox->run($input)[$id];
            $result = ['invoice_number' => isset($payload['DocNumber']) ? (string) $payload['DocNumber'] : null] + $result;
            $result['seconds'] = $state['report']['invoices'][$id]['seconds'];
            $result['technical_error'] = (bool) array_intersect_key($state['failed_items'], $pending['queue']) || $result['final_state'] === 'failed';
            $state['report']['invoices'][$id] = $result;
            $state['pending'] = null;
            $state['offset']++;
        } catch (\Throwable $e) {
            $state['report']['invoices'][$id] = array_replace($state['report']['invoices'][$id] ?? [], [
                'invoice_number' => $state['report']['invoices'][$id]['invoice_number'] ?? null,
                'consulted' => ! str_starts_with((string) $id, 'position:'), 'passed' => false, 'technical_error' => true,
                'change' => 'not_processed', 'final_state' => 'failed', 'selection_result' => 'review',
                'line_count' => null, 'total' => null, 'balance' => null,
                'seconds' => $state['report']['invoices'][$id]['seconds'] ?? 0,
                'filters' => $filters ?? null,
                'import_diagnostics' => $e instanceof InvoiceDataException ? [['message' => $e->getMessage()] + $e->context] : [],
                'operational_diagnostics' => [],
                'errors' => ['Invoice read or isolated processing failed; other selected invoices will continue.']]);
            $state['pending'] = null;
            $state['offset']++;
        } finally {
            if (isset($state['report']['invoices'][$id])) {
                $state['report']['invoices'][$id]['seconds'] += round(microtime(true) - $started, 3);
            }
            if ($state['offset'] >= count($state['report']['selected_positions'])) {
                $state['report']['status'] = 'completed';
                $state['report']['passed'] = collect($state['report']['invoices'])->every(fn ($row) => $row['passed']);
            }
        }
    }

    private function save(string $run, array &$state): void
    {
        $summary = ['selected' => count($state['report']['selected_positions']), 'consulted' => 0, 'pending_or_partial' => 0,
            'pending_found' => $state['report']['pending_invoices_found'] ?? $state['report']['available_invoices'], 'without_product' => 0, 'candidates' => 0,
            'paid' => 0, 'with_product' => 0, 'excluded_payment' => 0, 'excluded_category' => 0,
            'both_filters' => 0, 'review' => 0, 'technical_errors' => 0, 'processed' => $state['offset']];
        foreach ($state['report']['invoices'] as $row) {
            $filters = $row['filters'] ?? [];
            $summary['consulted'] += ! empty($row['consulted']);
            $summary['pending_or_partial'] += in_array($filters['payment_state'] ?? null, ['pending', 'partial'], true);
            $summary['paid'] += ($filters['payment_state'] ?? null) === 'paid';
            $summary['with_product'] += ($filters['product_items'] ?? 0) > 0;
            $summary['without_product'] += ($filters['category_filter'] ?? null) === 'excluded';
            $summary['candidates'] += ($row['selection_result'] ?? null) === 'candidate';
            $summary['excluded_payment'] += ($filters['payment_filter'] ?? null) === 'excluded';
            $summary['excluded_category'] += ($filters['category_filter'] ?? null) === 'excluded';
            $summary['both_filters'] += ($filters['payment_filter'] ?? null) === 'passed' && ($filters['category_filter'] ?? null) === 'passed';
            $summary['review'] += ($row['selection_result'] ?? null) === 'review';
            $summary['technical_errors'] += ! empty($row['technical_error']);
        }
        $summary['technical_errors'] += count($state['report']['errors']);
        $state['report']['summary'] = $summary;
        Cache::put($this->key($run), Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), now()->addHour());
    }

    private function key(string $run): string
    {
        return 'quickbooks.random-dry-run.'.$run;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
    }

    private function assertConnection(string $realm, string $environment): void
    {
        app(QuickBooksSyncLock::class)->assertOwned();
        if ($this->gateway->realm() !== $realm || (string) config('services.quickbooks.env') !== $environment) {
            throw new \RuntimeException('QuickBooks connection changed during selective test.');
        }
    }

    private function localState(array $input, array $schema = []): array
    {
        $states = [];
        foreach ($input['ids'] as $id) {
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
            if (! isset($schema[$table])) {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException('Required selective test schema is unavailable.');
                }
                $schema[$table] = ['columns' => Schema::getColumns($table), 'indexes' => Schema::getIndexes($table)];
            }
            $local[$table] = $schema[$table] + ['rows' => $rows[$table]];
        }

        return [$local, $states];
    }
}
