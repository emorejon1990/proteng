<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InvoiceSelectiveVerification
{
    public function snapshot(string $id, bool $lock = false): array
    {
        $query = Invoice::where('quickbooks_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $invoice = $query->first();
        $rows = function (string $table, string $column, mixed $key) use ($lock): array {
            if ($key === null || ! Schema::hasTable($table)) {
                return [];
            }
            $query = DB::table($table)->where($column, $key)->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->get()->map(fn ($row) => (array) $row)->all();
        };
        $workOrder = $invoice?->work_order_id;

        return [
            'invoice' => $invoice?->getRawOriginal(),
            'lines' => $rows('invoice_lines', 'invoice_id', $invoice?->id),
            'work_orders' => $rows('work_orders', 'id', $workOrder),
            'quantities' => $rows('wo_ass', 'work_order_id', $workOrder),
            'installations' => $rows('installations', 'work_order_id', $workOrder),
            'protected_counts' => ['work_orders' => DB::table('work_orders')->count(), 'quantities' => DB::table('wo_ass')->count(), 'installations' => DB::table('installations')->count()],
        ];
    }

    public function verify(array $payload, array $before, Invoice $invoice, string $realm): array
    {
        $after = $this->snapshot($invoice->quickbooks_id);
        $checks = [];
        $add = function (string $name, bool $passed) use (&$checks) {
            $checks[$name] = $passed;
        };
        $lines = $invoice->lines()->get();
        $stored = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $add('accounting_payload', InvoiceSyncService::payloadMatches($invoice->raw_payload, $stored));
        $add('customer_reference', (string) $invoice->customer?->quickbooks_id === QuickBooksReadGateway::entityRef($payload['CustomerRef'] ?? null));
        $add('invoice_identity', $invoice->quickbooks_id === (string) $payload['Id']);
        $add('realm', $invoice->realm_id === $realm);
        $expectedStatus = strtolower(trim($payload['PrivateNote'] ?? '')) === 'voided' ? 'void' : ($payload['Balance'] > 0 ? 'open' : 'paid');
        $add('accounting_status', $invoice->status === $expectedStatus);
        $add('invoice_number', $invoice->invoice_number === ($payload['DocNumber'] ?? null));
        foreach (['BillAddr' => 'billing_address', 'ShipAddr' => 'shipping_address'] as $source => $field) {
            if (array_key_exists($source, $payload)) {
                $add($field, $payload[$source] === null ? $invoice->$field === null : InvoiceSyncService::payloadMatches($invoice->$field, $payload[$source]));
            }
        }
        $add('currency', $invoice->currency_code === QuickBooksReadGateway::ref($payload['CurrencyRef'] ?? null));
        $add('issued_date', $invoice->issued_at?->format('Y-m-d') === substr((string) $payload['TxnDate'], 0, 10));
        $add('due_date', $invoice->due_at?->format('Y-m-d') === (isset($payload['DueDate']) ? substr((string) $payload['DueDate'], 0, 10) : null));
        $add('sync_status', $invoice->sync_status === 'synced' && $invoice->sync_error === null);
        foreach (['TotalAmt' => 'total', 'Balance' => 'balance'] as $source => $field) {
            $add('accounting_'.$field, number_format((float) $payload[$source], 2, '.', '') === (string) $invoice->$field);
        }
        $add('line_count', $lines->count() === count($payload['Line']));
        $add('no_duplicate_lines', $lines->pluck('line_number')->unique()->count() === $lines->count()
            && $lines->pluck('quickbooks_line_id')->filter(fn ($id) => $id !== null && $id !== '')->duplicates()->isEmpty());
        $add('unique_invoice', Invoice::where('quickbooks_id', $invoice->quickbooks_id)->count() === 1);
        foreach ($lines as $index => $line) {
            $source = $payload['Line'][$index] ?? [];
            $add('line_'.$index.'_accounting', $line->detail_type === ($source['DetailType'] ?? null)
                && (string) $line->amount === number_format((float) ($source['Amount'] ?? 0), 2, '.', ''));
            $add('line_'.$index.'_payload', InvoiceSyncService::payloadMatches($line->raw_payload, $source));
            $detail = is_array($source[$source['DetailType'] ?? ''] ?? null) ? $source[$source['DetailType']] : [];
            foreach (['Qty' => 'quantity', 'UnitPrice' => 'unit_price'] as $field => $column) {
                $add('line_'.$index.'_'.$column, ! isset($detail[$field]) ? $line->$column === null
                    : number_format((float) $detail[$field], 6, '.', '') === (string) $line->$column);
            }
            $add('line_'.$index.'_tax_code', $line->tax_code === QuickBooksReadGateway::ref($detail['TaxCodeRef'] ?? null));
            if ($line->detail_type === 'SalesItemLineDetail') {
                $add('line_'.$index.'_item_reference', $line->quickbooks_item_id === QuickBooksReadGateway::ref($detail['ItemRef'] ?? null));
            }
            if ($line->quickbooks_item_id === 'SHIPPING_ITEM_ID') {
                $add('line_'.$index.'_shipping', $line->asset_id === null && $line->item_type === 'Shipping' && ! $line->isPhysical());
            }
            if (in_array($line->detail_type, ['SubTotalLineDetail', 'DiscountLineDetail'], true)) {
                $add('line_'.$index.'_nonphysical', $line->asset_id === null && $line->quickbooks_item_id === null);
            }
        }
        $add('discount_total', number_format((float) collect($payload['Line'])->where('DetailType', 'DiscountLineDetail')->sum('Amount'), 2, '.', '')
            === number_format((float) $invoice->discount_total, 2, '.', ''));
        $add('tax_total', ($payload['TxnTaxDetail']['TotalTax'] ?? null) === null ? $invoice->tax_total === null
            : number_format((float) $payload['TxnTaxDetail']['TotalTax'], 2, '.', '') === number_format((float) $invoice->tax_total, 2, '.', ''));
        $original = $before['invoice'];
        foreach (['work_order_id', 'operation_type', 'classified_at', 'classified_by', 'operation_snapshot'] as $field) {
            $add('preserved_'.$field, ($original[$field] ?? null) === ($after['invoice'][$field] ?? null));
        }
        foreach (['work_orders', 'quantities', 'installations', 'protected_counts'] as $field) {
            $add('preserved_'.$field, $before[$field] === $after[$field]);
        }
        $add('conflict_not_resolved', ($original['operation_status'] ?? null) !== 'sync_conflict' || $invoice->operation_status === 'sync_conflict');
        $unchanged = $original && InvoiceSyncService::payloadMatches(json_decode($original['raw_payload'] ?? 'null', true), $stored);
        $add('unchanged_line_ids', ! $unchanged || array_column($before['lines'], 'id') === array_column($after['lines'], 'id'));
        try {
            app(InvoiceRequirements::class)->resolve($invoice, false);
            $add('operational_requirements_valid', true);
        } catch (ValidationException $e) {
            $add('operational_requirements_valid', false);
        }

        return [
            'passed' => ! in_array(false, $checks, true), 'checks' => $checks,
            'change' => $invoice->operation_status === 'sync_conflict' ? 'conflict' : (! $original ? 'new' : ($unchanged ? 'unchanged' : 'modified')),
            'line_count' => $lines->count(), 'total' => (string) $invoice->total, 'final_state' => $invoice->operation_status,
            'before' => $this->summary($before), 'after' => $this->summary($after),
            'errors' => array_keys(array_filter($checks, fn ($passed) => ! $passed)),
        ];
    }

    public function summary(array $snapshot): array
    {
        return [
            'line_ids' => array_column($snapshot['lines'], 'id'),
            'work_order_id' => $snapshot['invoice']['work_order_id'] ?? null,
            'sync_status' => $snapshot['invoice']['sync_status'] ?? null,
            'snapshot_hash' => hash('sha256', $snapshot['invoice']['operation_snapshot'] ?? 'null'),
            'quantities_hash' => hash('sha256', json_encode($snapshot['quantities'], JSON_THROW_ON_ERROR)),
        ];
    }
}
