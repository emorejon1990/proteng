<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InvoiceSyncService
{
    public function __construct(private QuickBooksReadGateway $gateway, private CustomerSyncService $customers, private InvoiceRequirements $requirements) {}

    public function create(Customer $customer, array $items): never
    {
        throw new \LogicException('Invoices must be created in QuickBooks.');
    }

    public function sync(?User $actor = null): void
    {
        $actor ??= auth()->user();
        abort_unless($actor?->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
        $realm = $this->gateway->realm();
        $this->assertRealm($realm);
        $seen = [];
        try {
            for ($position = 1; ; $position += 100) {
                $page = $this->gateway->page('Invoice', $position);
                foreach ($page as $payload) {
                    $seen[(string) ($payload['Id'] ?? '')] = true;
                    try {
                        $this->import($payload, $realm);
                    } catch (\Throwable $e) {
                        $this->failed($payload['Id'] ?? null, $e);
                        throw $e;
                    }
                }
                if (count($page) < 100) {
                    break;
                }
            }
            Invoice::where('realm_id', $realm)->each(function (Invoice $invoice) use ($seen, $realm) {
                if (isset($seen[$invoice->quickbooks_id])) {
                    return;
                }
                // Absence from a successful full query warrants a read verification, never deletion.
                try {
                    $this->import($this->gateway->find('Invoice', $invoice->quickbooks_id), $realm);
                } catch (\Throwable $e) {
                    $this->failed($invoice->quickbooks_id, $e);
                    if ($invoice->work_order_id) {
                        $invoice->update(['operation_status' => 'requires_review']);
                        $invoice->events()->create(['event' => 'Invoice Source Unavailable', 'work_order_id' => $invoice->work_order_id,
                            'metadata' => ['reason' => 'Missing from query and verification failed; review deletion, permissions or connection. Operation preserved.']]);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('Invoice synchronization failed', ['error' => $e->getMessage()]);
            InvoiceEvent::create(['event' => 'Invoice Sync Failed', 'metadata' => ['error' => 'QuickBooks synchronization interrupted. Check server logs.']]);
            throw $e;
        }
    }

    private function assertRealm(string $realm): void
    {
        if (Invoice::whereNotNull('realm_id')->where('realm_id', '!=', $realm)->exists()) {
            throw new \RuntimeException('Multiple QuickBooks companies are not supported by the existing customer/asset catalog.');
        }
    }

    public function import(array $p, string $realm): Invoice
    {
        $this->assertRealm($realm);
        if (empty($p['Id']) || ! isset($p['TotalAmt'], $p['Balance'], $p['TxnDate']) || ! isset($p['Line']) || ! is_array($p['Line']) || ! $p['Line']) {
            throw new \RuntimeException('Incomplete invoice payload; previous data was preserved.');
        }
        if (! is_numeric($p['TotalAmt']) || ! is_numeric($p['Balance'])) {
            throw new \RuntimeException('Invoice totals are invalid; previous data was preserved.');
        }
        $ref = QuickBooksReadGateway::ref($p['CustomerRef'] ?? null);
        if (! $ref) {
            throw new \RuntimeException('Invoice has no QuickBooks customer.');
        }
        $customer = Customer::where('quickbooks_id', $ref)->first();
        if (! $customer) {
            $customer = $this->customers->import($this->gateway->find('Customer', $ref));
        }
        $lines = [];
        foreach ($p['Line'] as $number => $line) {
            if (! is_array($line) || empty($line['DetailType']) || ! isset($line['Amount'])) {
                throw new \RuntimeException('Incomplete line; previous data was preserved.');
            }
            $detail = $line[$line['DetailType']] ?? [];
            $itemId = QuickBooksReadGateway::ref($detail['ItemRef'] ?? null);
            $itemType = null;
            $asset = null;
            if ($itemId) {
                $item = $this->gateway->find('Item', $itemId);
                $itemType = $item['Type'] ?? null;
                if (in_array($itemType, ['Inventory', 'NonInventory'], true)) {
                    $matches = Asset::where('quickbooks_id', $itemId)->limit(2)->get();
                    $asset = $matches->count() === 1 ? $matches->first() : null;
                }
            }
            $lines[] = ['quickbooks_line_id' => $line['Id'] ?? null, 'line_number' => $number + 1,
                'detail_type' => $line['DetailType'], 'quickbooks_item_id' => $itemId, 'item_type' => $itemType,
                'asset_id' => $asset?->id, 'description' => $line['Description'] ?? null,
                'quantity' => $detail['Qty'] ?? null, 'unit_price' => $detail['UnitPrice'] ?? null,
                'amount' => $line['Amount'], 'tax_code' => QuickBooksReadGateway::ref($detail['TaxCodeRef'] ?? null), 'raw_payload' => $line];
        }

        return DB::transaction(function () use ($p, $realm, $customer, $lines) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('quickbooks_id', (string) $p['Id'])->lockForUpdate()->first();
            $new = ! $invoice;
            $invoice ??= new Invoice(['quickbooks_id' => (string) $p['Id']]);
            $changed = $invoice->raw_payload !== $p;
            $invoice->fill(['realm_id' => $realm, 'customer_id' => $customer->id, 'invoice_number' => $p['DocNumber'] ?? null,
                'total' => $p['TotalAmt'], 'balance' => $p['Balance'],
                'status' => strtolower(trim($p['PrivateNote'] ?? '')) === 'voided' ? 'void' : ($p['Balance'] > 0 ? 'open' : 'paid'),
                'issued_at' => $p['TxnDate'], 'due_at' => $p['DueDate'] ?? null,
                'currency_code' => QuickBooksReadGateway::ref($p['CurrencyRef'] ?? null),
                'tax_total' => $p['TxnTaxDetail']['TotalTax'] ?? null,
                'discount_total' => collect($lines)->where('detail_type', 'DiscountLineDetail')->sum('amount'),
                'quickbooks_updated_at' => $p['MetaData']['LastUpdatedTime'] ?? null, 'last_synced_at' => now(),
                'sync_status' => 'synced', 'sync_error' => null, 'raw_payload' => $p]);
            foreach (['BillAddr' => 'billing_address', 'ShipAddr' => 'shipping_address'] as $source => $target) {
                if (array_key_exists($source, $p)) {
                    $invoice->$target = $p[$source];
                }
            }
            $invoice->save();
            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);
            try {
                $current = $this->requirements->resolve($invoice, false);
            } catch (ValidationException $e) {
                $current = null;
            }
            if ($invoice->work_order_id) {
                $snapshot = $invoice->operation_snapshot;
                $conflict = $current === null || $current != ($snapshot['requirements'] ?? [])
                    || (string) $customer->quickbooks_id !== ($snapshot['customer_quickbooks_id'] ?? '') || $invoice->status === 'void';
                if ($conflict) {
                    $invoice->operation_status = 'sync_conflict';
                    $invoice->events()->create(['event' => 'Invoice Sync Conflict', 'work_order_id' => $invoice->work_order_id,
                        'metadata' => ['snapshot' => $snapshot, 'current_requirements' => $current, 'accounting_status' => $invoice->status]]);
                }
            } else {
                $invoice->operation_status = $current === null || ! $current ? 'requires_review' : 'unclassified';
            }
            $invoice->save();
            if ($new || $changed) {
                $invoice->events()->create(['event' => $new ? 'Invoice Imported' : 'Invoice Updated']);
            }

            return $invoice->fresh();
        }, 3);
    }

    private function failed($id, \Throwable $e): void
    {
        $invoice = $id ? Invoice::where('quickbooks_id', $id)->first() : null;
        $message = 'Synchronization failed; previous data preserved. Check connection and server logs.';
        if ($invoice) {
            $invoice->update(['sync_status' => 'failed', 'sync_error' => $message]);
        }
        InvoiceEvent::create(['invoice_id' => $invoice?->id, 'event' => 'Invoice Sync Failed', 'metadata' => ['quickbooks_id' => $id, 'error' => $message]]);
        Log::error($message, ['quickbooks_id' => $id, 'exception' => $e->getMessage()]);
    }
}
