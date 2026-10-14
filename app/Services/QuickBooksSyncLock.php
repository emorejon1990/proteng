<?php

namespace App\Services;

use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

class QuickBooksSyncLock
{
    public const KEY = 'proteng.quickbooks.synchronization';

    private bool $held = false;

    private ?Lock $lock = null;

    public function run(callable $callback): mixed
    {
        if ($this->held) {
            $this->assertOwned();

            return $callback();
        }
        $store = Cache::store();
        if (! $store->getStore() instanceof LockProvider || (! app()->environment('testing') && config('cache.default') === 'array')) {
            throw new \RuntimeException('A shared atomic cache is required for synchronization.');
        }
        $lock = $store->lock(self::KEY, 3600);
        if (! $lock->get()) {
            throw new \RuntimeException('Another QuickBooks synchronization is running. Retry after it finishes.');
        }
        $this->held = true;
        $this->lock = $lock;
        try {
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
