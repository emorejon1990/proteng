<?php

namespace App\Services;

use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuickBooksSyncLock
{
    public const KEY = 'proteng.quickbooks.synchronization';

    private bool $held = false;

    private ?Lock $lock = null;

    public function run(callable $callback, bool $invoiceBatch = false, int $seconds = 3600): mixed
    {
        if ($this->held) {
            $this->assertOwned();

            return $callback();
        }
        $store = Cache::store();
        if (! $store->getStore() instanceof LockProvider || (! app()->environment('testing') && config('cache.default') === 'array')) {
            throw new \RuntimeException('A shared atomic cache is required for synchronization.');
        }
        $lock = $store->lock(self::KEY, $seconds);
        if (! $lock->get()) {
            throw new \RuntimeException('Another QuickBooks synchronization is running. Retry after it finishes.');
        }
        $this->held = true;
        $this->lock = $lock;
        try {
            if (! $invoiceBatch && Schema::hasTable('invoice_sync_sessions')) {
                $session = DB::table('invoice_sync_sessions')->where('id', 1)->where('updated_at', '>', now()->subMinutes(10))->first();
                if ($session && (json_decode($session->state, true)['status'] ?? null) === 'running') {
                    throw new \RuntimeException('An incremental Invoice sync is active. Resume it or wait for its reservation to expire.');
                }
            }

            return $callback();
        } finally {
            $this->held = false;
            $this->lock = null;
            $lock->release();
        }
    }

    public function assertOwned(): void
    {
        if ($this->held && ! $this->lock?->isOwnedByCurrentProcess()) {
            throw new \RuntimeException('Synchronization lock expired or was lost. No further writes are allowed.');
        }
    }
}
