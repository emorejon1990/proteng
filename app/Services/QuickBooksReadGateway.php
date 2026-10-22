<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\QuickbooksToken;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPReferenceType;

/** Read-only accounting boundary. */
class QuickBooksReadGateway
{
    private ?string $expectedRealm = null;

    public function __construct(private QuickBooksService $qb) {}

    public function realm(): string
    {
        $realm = QuickbooksToken::whereNotNull('realm_id')->latest()->value('realm_id');
        if (! $realm) {
            throw new \RuntimeException('Connect QuickBooks before synchronizing.');
        }

        if ($this->expectedRealm !== null && $this->expectedRealm !== (string) $realm) {
            throw new \RuntimeException('QuickBooks company changed during synchronization. No mixed-company import is allowed.');
        }

        return $this->expectedRealm = (string) $realm;
    }

    public function page(string $entity, int $position, int $size = 100, bool $pendingOnly = false): array
    {
        if (! in_array($entity, ['Invoice', 'Customer', 'Item'], true)) {
            throw new \InvalidArgumentException('Unsupported entity.');
        }
        if ($pendingOnly && $entity !== 'Invoice') {
            throw new \InvalidArgumentException('Pending balance selection only supports invoices.');
        }
        $ds = $this->qb->ds($this->realm());
        $active = $pendingOnly ? " WHERE Balance > '0'" : (in_array($entity, ['Customer', 'Item'], true) ? ' WHERE Active IN (true, false)' : '');
        $result = $ds->Query("SELECT * FROM {$entity}{$active} ORDERBY Id STARTPOSITION {$position} MAXRESULTS {$size}");
        $this->check($ds);
        if ($result === null) {
            return [];
        }
        if (! is_array($result)) {
            throw new \RuntimeException('Incomplete QuickBooks response.');
        }

        return array_map(self::payload(...), $result);
    }

    public function invoiceCount(bool $pendingOnly = false): int
    {
        $ds = $this->qb->ds($this->realm());
        $count = $ds->Query('SELECT COUNT(*) FROM Invoice'.($pendingOnly ? " WHERE Balance > '0'" : ''));
        $this->check($ds);
        if (! is_int($count) || $count < 0) {
            throw new \RuntimeException('QuickBooks returned an invalid invoice count.');
        }

        return $count;
    }

    public function find(string $entity, string $id): array
    {
        if (! in_array($entity, ['Invoice', 'Customer', 'Item'], true)) {
            throw new \InvalidArgumentException('Unsupported entity.');
        }
        self::entityRef($id, ['entity' => $entity]);
        $ds = $this->qb->ds($this->realm());
        $result = $ds->FindById($entity, $id);
        $this->check($ds);
        if (! $result) {
            throw new \RuntimeException("QuickBooks {$entity} {$id} is unavailable.");
        }

        $payload = self::payload($result);
        if ((string) ($payload['Id'] ?? '') !== $id) {
            throw new \RuntimeException('QuickBooks returned a mismatched entity response.');
        }

        return $payload;
    }

    public function pdf(Invoice $invoice): string
    {
        if (! $invoice->realm_id || $invoice->realm_id !== $this->realm()) {
            throw new \RuntimeException('Invoice company differs from the connected company. Sync first.');
        }
        $ds = $this->qb->ds($invoice->realm_id);
        $entity = new IPPInvoice(['Id' => $invoice->quickbooks_id]);
        $pdf = $ds->DownloadPDF($entity, null, true);
        $this->check($ds);
        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('QuickBooks returned no valid PDF.');
        }

        return $pdf;
    }

    private function check($ds): void
    {
        if ($ds->getLastError()) {
            throw new \RuntimeException('QuickBooks request failed. Check authentication, rate limits and connection, then retry.');
        }
    }

    public static function payload($value): array
    {
        // SDK declares every possible field as null, including fields absent in the response.
        // Strip those object defaults, but preserve explicit null/empty values in raw arrays.
        $normalize = function ($entry) use (&$normalize) {
            if (is_object($entry)) {
                return array_map($normalize, array_filter(get_object_vars($entry), fn ($v) => $v !== null));
            }

            return is_array($entry) ? array_map($normalize, $entry) : $entry;
        };

        return $normalize($value);
    }

    public static function ref($value): ?string
    {
        if ($value instanceof IPPReferenceType) {
            $value = $value->value;
        }
        $id = is_array($value) ? ($value['value'] ?? $value['Value'] ?? null) : $value;
        if ($id === null || $id === '') {
            if (is_array($value) && ! array_key_exists('value', $value) && ! array_key_exists('Value', $value)) {
                throw new InvoiceDataException('Malformed QuickBooks reference.');
            }

            return null;
        }
        if (! is_string($id) && ! is_int($id)) {
            throw new InvoiceDataException('Malformed QuickBooks reference.');
        }

        return (string) $id;
    }

    public static function entityRef($value, array $context = []): string
    {
        try {
            $id = self::ref($value);
            if ($id === null || ! preg_match('/^[0-9]+$/D', $id)) {
                throw new InvoiceDataException('Invalid QuickBooks reference.');
            }

            return $id;
        } catch (InvoiceDataException $e) {
            $category = $value === null ? 'missing' : (is_array($value) ? 'array' : ($value instanceof IPPReferenceType ? 'sdk-reference' : gettype($value)));
            if (is_array($value)) {
                $candidate = $value['value'] ?? $value['Value'] ?? null;
                $category = ! array_key_exists('value', $value) && ! array_key_exists('Value', $value) ? 'missing-value-key'
                    : (is_array($candidate) || is_object($candidate) ? 'nested-value' : ($candidate === null || $candidate === '' ? 'empty-value' : 'invalid-value'));
            }
            throw new InvoiceDataException('Invalid QuickBooks reference; previous data preserved.', $context + ['reference_format' => $category]);
        }
    }
}
