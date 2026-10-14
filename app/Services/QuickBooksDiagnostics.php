<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class QuickBooksDiagnostics
{
    public static function failure(string $operation, \Throwable $e): void
    {
        // SDK messages and exception arguments may contain OAuth responses or secrets.
        Log::error('QuickBooks operation failed', [
            'operation' => $operation,
            'exception_class' => $e::class,
            'source' => basename($e->getFile()).':'.$e->getLine(),
            'trace' => array_map(static fn (array $frame) => [
                'file' => basename($frame['file'] ?? ''),
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'],
            ], array_slice($e->getTrace(), 0, 12)),
            'config_cached' => app()->configurationIsCached(),
            'configured' => array_map(static fn (string $key) => filled(config('services.quickbooks.'.$key)),
                array_combine(['client_id', 'client_secret', 'redirect_uri', 'scope', 'env'],
                    ['client_id', 'client_secret', 'redirect_uri', 'scope', 'env'])),
        ]);
    }
}
