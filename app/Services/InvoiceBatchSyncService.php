<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable checkpoints live in the application database, never in the Dry Run sandbox. */
class InvoiceBatchSyncService
{
    public function __construct(private QuickBooksReadGateway $gateway, private InvoiceSyncService $importer) {}

    public function current(User $actor): array
    {
        $this->authorize($actor);
        if (! Schema::hasTable('invoice_sync_sessions')) {
            return [];
        }
        $row = DB::table('invoice_sync_sessions')->where('id', 1)->first();

        return $row ? json_decode($row->state, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function start(User $actor): array
    {
        $this->authorize($actor);

        return app(QuickBooksSyncLock::class)->run(fn () => DB::transaction(function () use ($actor) {
            $state = $this->current($actor);
            if ($state && $state['status'] !== 'completed') {
                $this->connection($state);
                $state['status'] = 'running';
                $state['error'] = null;
                if ($state['phase'] === 'discover') {
                    // Offsets can move while the browser is disconnected; rebuild the manifest.
                    $state['position'] = 1;
                    $state['ids'] = [];
                }
            } else {
                $this->importer->assertRealm($this->gateway->realm());
                $state = ['run_id' => (string) Str::uuid(), 'realm' => $this->gateway->realm(),
                    'environment' => (string) config('services.quickbooks.env'), 'actor_id' => $actor->id,
                    'started_at' => now()->toDateTimeString(), 'status' => 'running', 'phase' => 'discover', 'position' => 1, 'ids' => [], 'cursor' => 0,
                    'summary' => ['processed' => 0, 'synced' => 0, 'excluded' => 0, 'failed' => 0, 'review' => 0], 'error' => null];
                InvoiceEvent::create(['event' => 'Invoice Batch Sync Started', 'user_id' => $actor->id,
                    'metadata' => ['run_id' => $state['run_id']]]);
            }
            $this->save($state);

            return $state;
        }), invoiceBatch: true, seconds: 120);
    }

    public function advance(User $actor, string $run): array
    {
        $this->authorize($actor);

        return app(QuickBooksSyncLock::class)->run(function () use ($actor, $run) {
            $state = $this->current($actor);
            abort_unless(($state['run_id'] ?? null) === $run, 409);
            if ($state['status'] !== 'running') {
                return $state;
            }
            try {
                $this->connection($state);
                $size = max(1, min(20, (int) config('services.quickbooks.invoice_batch_size', 5)));
                if ($state['phase'] === 'discover') {
                    $page = $this->gateway->page('Invoice', $state['position'], $size);
                    foreach ($page as $payload) {
                        $state['ids'][] = QuickBooksReadGateway::entityRef($payload['Id'] ?? null);
                    }
                    $state['ids'] = array_values(array_unique($state['ids']));
                    $state['position'] += count($page);
                    if (count($page) < $size) {
                        // Preserve the old reconciliation of local invoices absent from the listing.
                        $state['ids'] = array_values(array_unique(array_merge($state['ids'],
                            Invoice::where('realm_id', $state['realm'])->pluck('quickbooks_id')->all())));
                        $state['phase'] = 'import';
                    }
                    $this->save($state);

                    return $state;
                }
                $this->importer->resetReadCache();
                for ($n = 0; $n < $size && $state['cursor'] < count($state['ids']); $n++) {
                    $id = (string) $state['ids'][$state['cursor']];
                    // Never replay a stale listing payload, even after an interrupted request.
                    $payload = $this->gateway->find('Invoice', $id);
                    if (QuickBooksReadGateway::entityRef($payload['Id'] ?? null) !== $id) {
                        throw new \RuntimeException('Invoice response identity changed.');
                    }
                    $this->connection($state);
                    DB::transaction(function () use (&$state, $payload) {
                        $outcome = $this->importer->syncPayload($payload, $state['realm']);
                        $state['summary'][$outcome]++;
                        $state['summary']['processed']++;
                        $state['cursor']++;
                        // Import, event and checkpoint commit together, including after a client timeout.
                        $this->save($state);
                    });
                }
                if ($state['cursor'] === count($state['ids'])) {
                    DB::transaction(function () use (&$state) {
                        $state['status'] = 'completed';
                        InvoiceEvent::create(['event' => 'Invoice Batch Sync Completed', 'user_id' => $state['actor_id'],
                            'metadata' => ['run_id' => $state['run_id']] + $state['summary']]);
                        $this->save($state);
                    });
                }
            } catch (\Throwable $e) {
                // Reload: a rolled back import must never advance its durable cursor.
                $state = $this->current($actor);
                $state['status'] = 'stopped';
                $state['error'] = 'Synchronization stopped. Check QuickBooks connection and server diagnostics, then press Sync Invoices to resume. Saved imports are preserved.';
                Log::error('Invoice batch synchronization stopped', ['run_id' => $run,
                    'quickbooks_id' => $state['ids'][$state['cursor']] ?? null, 'exception_type' => get_class($e)]);
                DB::transaction(function () use ($state) {
                    InvoiceEvent::create(['event' => 'Invoice Batch Sync Stopped', 'user_id' => $state['actor_id'],
                        'metadata' => ['run_id' => $state['run_id'], 'cursor' => $state['cursor'], 'error' => $state['error']]]);
                    $this->save($state);
                });
            }

            return $state;
        }, invoiceBatch: true, seconds: 120);
    }

    private function connection(array $state): void
    {
        app(QuickBooksSyncLock::class)->assertOwned();
        if ($this->gateway->realm() !== $state['realm'] || (string) config('services.quickbooks.env') !== $state['environment']) {
            throw new \RuntimeException('QuickBooks company or environment changed. Restore the original connection before resuming.');
        }
        $this->importer->assertRealm($state['realm']);
    }

    private function save(array $state): void
    {
        app(QuickBooksSyncLock::class)->assertOwned();
        DB::table('invoice_sync_sessions')->updateOrInsert(['id' => 1], [
            'run_id' => $state['run_id'], 'state' => json_encode($state, JSON_THROW_ON_ERROR),
            'created_at' => $state['started_at'], 'updated_at' => now(),
        ]);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
    }
}
