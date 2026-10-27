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

    private ?array $itemCache = [];

    private array $itemFailures = [];

    public function resetReadCache(): void
    {
        $this->itemCache = [];
        $this->itemFailures = [];
    }

    /** Same filtering/import path as full sync; caller owns the checkpoint transaction. */
    public function syncPayload(array $payload, string $realm): string
    {
        $id = QuickBooksReadGateway::entityRef($payload['Id'] ?? null, ['entity' => 'Invoice']);
        try {
            $filters = $this->filtersFor($payload);
            if ($filters['result'] !== 'candidate' && ! Invoice::where('quickbooks_id', $id)->exists()) {
                InvoiceEvent::create(['event' => 'Invoice Filter '.ucfirst($filters['result']),
                    'metadata' => ['quickbooks_id' => $id, 'filters' => $filters]]);

                return $filters['result'];
            }
            $this->import($payload, $realm, $filters);

            return 'synced';
        } catch (InvoiceDataException $e) {
            $this->failed($id, $e);

            return 'failed';
        }
    }

    public function filtersFor(array $payload): array
    {
        return $this->requirements->filters($payload, function (string $id): array {
            $this->gateway->realm();
            if (isset($this->itemFailures[$id])) {
                throw new InvoiceDataException('Item or category is unavailable.');
            }
            try {
                return $this->itemCache[$id] ??= $this->gateway->find('Item', $id);
            } catch (\Throwable $e) {
                $this->itemFailures[$id] = true;
                throw $e;
            }
        });
    }

    public function sync(?User $actor = null): array
    {
        $actor ??= auth()->user();
        abort_unless($actor?->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);

        return app(QuickBooksSyncLock::class)->run(fn () => $this->syncLocked($actor));
    }

    private function syncLocked(?User $actor): array
    {
        $actor ??= auth()->user();
        abort_unless($actor?->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
        $summary = ['synced' => 0, 'failed' => 0, 'excluded' => 0, 'review' => 0];
        $this->itemCache = [];
        $this->itemFailures = [];
        $seen = [];
        $currentId = null;
        $process = function (array $payload) use (&$summary, &$seen, &$currentId) {
            // A missing/invalid invoice identity makes the listing unsafe for reconciliation.
            $id = QuickBooksReadGateway::entityRef($payload['Id'] ?? null, ['entity' => 'Invoice']);
            $currentId = $id;
            $seen[$id] = true;
            $summary[$this->syncPayload($payload, $this->gateway->realm())]++;
            $currentId = null;
        };
        try {
            $realm = $this->gateway->realm();
            $this->assertRealm($realm);
            for ($position = 1; ; $position += 100) {
                $page = $this->gateway->page('Invoice', $position);
                foreach ($page as $payload) {
                    $process($payload);
                }
                if (count($page) < 100) {
                    break;
                }
            }
            // Only reconcile after a complete listing. API/database failures stop immediately.
            Invoice::where('realm_id', $realm)->each(function (Invoice $invoice) use (&$seen, &$currentId, $process) {
                if (! isset($seen[$invoice->quickbooks_id])) {
                    $currentId = $invoice->quickbooks_id;
                    $process($this->gateway->find('Invoice', $invoice->quickbooks_id));
                }
            });
            InvoiceEvent::create(['event' => $summary['failed'] || $summary['review'] ? 'Invoice Sync Partial' : 'Invoice Sync Completed', 'metadata' => $summary]);

            return $summary;
        } catch (\Throwable $e) {
            Log::error('Invoice synchronization stopped', ['exception_type' => get_class($e)]);
            try {
                InvoiceEvent::create(['invoice_id' => $currentId ? Invoice::where('quickbooks_id', $currentId)->value('id') : null,
                    'event' => 'Invoice Sync Stopped', 'metadata' => $summary + ['quickbooks_id' => $currentId, 'error' => 'Synchronization stopped. Review the connection, company and server diagnostics. Previous successful imports remain saved.']]);
            } catch (\Throwable $auditError) {
                Log::error('Unable to persist stopped synchronization event', ['exception_type' => get_class($auditError)]);
            }
            throw $e;
        } finally {
            $this->itemCache = null;
        }
    }

    public function assertRealm(string $realm): void
    {
        if (Invoice::whereNotNull('realm_id')->where('realm_id', '!=', $realm)->exists()) {
            throw new \RuntimeException('Multiple QuickBooks companies are not supported by the existing customer/asset catalog.');
        }
    }

    public function import(array $p, string $realm, ?array $filters = null): Invoice
    {
        app(QuickBooksSyncLock::class)->assertOwned();
        $this->assertRealm($realm);
        $p = QuickBooksReadGateway::payload($p);
        QuickBooksReadGateway::entityRef($p['Id'] ?? null, ['entity' => 'Invoice']);
        if (empty($p['Id']) || ! isset($p['TotalAmt'], $p['Balance'], $p['TxnDate']) || ! isset($p['Line']) || ! is_array($p['Line']) || ! $p['Line']) {
            throw new InvoiceDataException('Incomplete invoice payload; previous data was preserved.');
        }
        if (! is_numeric($p['TotalAmt']) || ! is_numeric($p['Balance']) || ! is_finite((float) $p['TotalAmt']) || ! is_finite((float) $p['Balance'])) {
            throw new InvoiceDataException('Invoice totals are invalid; previous data was preserved.');
        }
        $ref = QuickBooksReadGateway::entityRef($p['CustomerRef'] ?? null, ['entity' => 'Customer']);
        $details = [];
        foreach ($p['Line'] as $number => $line) {
            $details[$number] = $this->lineDetail($line, $number);
        }
        $filters ??= $this->filtersFor($p);
        // Lock and compare before Item reads. An unchanged invoice must retain its line identities.
        $storedPayload = json_decode(json_encode($p, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $unchanged = DB::transaction(function () use ($p, $realm, $storedPayload) {
            $invoice = Invoice::where('quickbooks_id', (string) $p['Id'])->lockForUpdate()->first();
            if (! $invoice || $invoice->realm_id !== $realm || ! self::payloadMatches($invoice->raw_payload, $storedPayload)) {
                return null;
            }
            if ($invoice->sync_status !== 'synced' || $invoice->sync_error !== null) {
                $invoice->update(['sync_status' => 'synced', 'sync_error' => null]);
            }
            $invoice->events()->create(['event' => 'Invoice Unchanged', 'metadata' => ['quickbooks_id' => $invoice->quickbooks_id]]);
            app(QuickBooksSyncLock::class)->assertOwned();

            return $invoice->fresh();
        });
        if ($unchanged) {
            return $unchanged;
        }
        $customer = Customer::where('quickbooks_id', $ref)->first();
        $customerPayload = $customer ? null : $this->gateway->find('Customer', $ref);
        if ($customerPayload !== null && (QuickBooksReadGateway::entityRef($customerPayload['Id'] ?? null) !== $ref || empty($customerPayload['DisplayName']))) {
            throw new InvoiceDataException('Incomplete or mismatched customer response.', ['entity' => 'Customer']);
        }
        $lines = [];
        foreach ($p['Line'] as $number => $line) {
            ['detail' => $detail, 'item_id' => $itemId, 'shipping' => $shipping] = $details[$number];
            $itemType = $shipping ? 'Shipping' : null;
            $asset = null;
            if ($itemId && ! $shipping) {
                $this->gateway->realm();
                if (isset($this->itemFailures[$itemId])) {
                    throw new InvoiceDataException('Item could not be verified; previous data preserved.',
                        ['code' => 'unknown_item_category', 'line_index' => $number, 'item_ref' => $itemId]);
                }
                $item = $this->itemCache[$itemId] ?? $this->gateway->find('Item', $itemId);
                $itemContext = ['code' => 'item_response_mismatch', 'rule' => 'item_response_identity',
                    'line_index' => $number, 'line_position' => $number + 1, 'detail_type' => $line['DetailType'],
                    'item_ref' => $itemId, 'item_type' => is_string($item['Type'] ?? null) ? $item['Type'] : null,
                    'quantity' => $detail['Qty'] ?? null, 'asset_id' => null, 'asset_quickbooks_id' => null];
                try {
                    $returnedId = QuickBooksReadGateway::entityRef($item['Id'] ?? null);
                } catch (InvoiceDataException $e) {
                    throw new InvoiceDataException('Item response identity is missing or invalid; previous data preserved.', $itemContext);
                }
                if ($returnedId !== $itemId) {
                    throw new InvoiceDataException('Item response does not match the requested ItemRef; previous data preserved.', $itemContext);
                }
                if ($this->itemCache !== null) {
                    $this->itemCache[$itemId] = $item;
                }
                $itemType = is_string($item['Type'] ?? null) ? $item['Type'] : null;
                if (($filters['items'][$itemId]['membership'] ?? null) === 'product' && in_array($itemType, ['Inventory', 'NonInventory'], true)) {
                    $matches = Asset::where('quickbooks_id', $itemId)->limit(2)->get();
                    $asset = $matches->count() === 1 ? $matches->first() : null;
                }
            }
            $lines[] = ['quickbooks_line_id' => $line['Id'] ?? null, 'line_number' => $number + 1,
                'detail_type' => $line['DetailType'], 'quickbooks_item_id' => $itemId, 'item_type' => $itemType,
                'asset_id' => $asset?->id, 'description' => $line['Description'] ?? null,
                'quantity' => $detail['Qty'] ?? null, 'unit_price' => $detail['UnitPrice'] ?? null,
                'amount' => $line['Amount'] ?? 0, 'tax_code' => QuickBooksReadGateway::ref($detail['TaxCodeRef'] ?? null), 'raw_payload' => $line];
        }

        return DB::transaction(function () use ($p, $realm, $customer, $customerPayload, $lines, $storedPayload, $filters) {
            app(QuickBooksSyncLock::class)->assertOwned();
            $customer ??= $this->customers->import($customerPayload);
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('quickbooks_id', (string) $p['Id'])->lockForUpdate()->first();
            // Another importer may have saved this same payload while Item reads were in flight.
            if ($invoice && $invoice->realm_id === $realm && self::payloadMatches($invoice->raw_payload, $storedPayload)) {
                if ($invoice->sync_status !== 'synced' || $invoice->sync_error !== null) {
                    $invoice->update(['sync_status' => 'synced', 'sync_error' => null]);
                }
                $invoice->events()->create(['event' => 'Invoice Unchanged', 'metadata' => ['quickbooks_id' => $invoice->quickbooks_id]]);
                app(QuickBooksSyncLock::class)->assertOwned();

                return $invoice->fresh();
            }
            $new = ! $invoice;
            $invoice ??= new Invoice(['quickbooks_id' => (string) $p['Id']]);
            $changed = ! self::payloadMatches($invoice->raw_payload, $p);
            $invoice->fill(['realm_id' => $realm, 'customer_id' => $customer->id, 'invoice_number' => $p['DocNumber'] ?? null,
                'total' => $p['TotalAmt'], 'balance' => $p['Balance'],
                'status' => strtolower(trim($p['PrivateNote'] ?? '')) === 'voided' ? 'void' : ($p['Balance'] > 0 ? 'open' : 'paid'),
                'issued_at' => $p['TxnDate'], 'due_at' => $p['DueDate'] ?? null,
                'currency_code' => QuickBooksReadGateway::ref($p['CurrencyRef'] ?? null),
                'tax_total' => $p['TxnTaxDetail']['TotalTax'] ?? null,
                'discount_total' => collect($lines)->where('detail_type', 'DiscountLineDetail')->sum('amount'),
                'quickbooks_updated_at' => $p['MetaData']['LastUpdatedTime'] ?? null, 'last_synced_at' => now(),
                'sync_status' => 'synced', 'sync_error' => null, 'raw_payload' => $p]);
            $invoice->metadata = array_replace($invoice->metadata ?? [], ['operational_filters' => $filters]);
            foreach (['BillAddr' => 'billing_address', 'ShipAddr' => 'shipping_address'] as $source => $target) {
                if (array_key_exists($source, $p)) {
                    $invoice->$target = $p[$source];
                }
            }
            $invoice->save();
            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);
            $diagnostics = [];
            try {
                $current = ! $invoice->work_order_id && $filters['result'] === 'excluded' ? [] : $this->requirements->resolve($invoice, false, $filters);
            } catch (ValidationException $e) {
                $current = null;
                $diagnostics = InvoiceOperationalException::diagnostics($e);
            }
            if ($filters['result'] === 'review') {
                $current = null;
                $diagnostics[] = ['code' => 'filter_review', 'message' => 'Payment or category requires review.'];
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
                $invoice->operation_status = $current === null ? 'requires_review' : ($current ? 'unclassified' : 'out_of_scope');
            }
            $invoice->save();
            $invoice->events()->create(['event' => $new ? 'Invoice Imported' : ($changed ? 'Invoice Updated' : 'Invoice Unchanged'), 'metadata' => ['quickbooks_id' => $invoice->quickbooks_id, 'operational_diagnostics' => $diagnostics]]);
            app(QuickBooksSyncLock::class)->assertOwned();

            return $invoice->fresh();
        }, 3);
    }

    /** JSON object key order is not meaningful (MySQL JSON can reorder it); line array order is. */
    public static function payloadMatches(?array $stored, array $incoming): bool
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($canonical, $value);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };
        $incoming = json_decode(json_encode($incoming, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $stored !== null && $canonical($stored) === $canonical($incoming);
    }

    private function lineDetail(mixed $line, int $number): array
    {
        if (! is_array($line) || ! is_string($line['DetailType'] ?? null) || $line['DetailType'] === '' || (! isset($line['Amount']) && ($line['DetailType'] ?? '') !== 'DescriptionOnly')) {
            throw new InvoiceDataException('Incomplete line; previous data was preserved.');
        }
        if (! in_array($line['DetailType'], ['SalesItemLineDetail', 'SubTotalLineDetail', 'DiscountLineDetail', 'GroupLineDetail', 'DescriptionOnly'], true)) {
            throw new InvoiceDataException('Unsupported invoice line type; previous data preserved.', ['code' => 'unsupported_line', 'rule' => 'supported_accounting_line', 'line_index' => $number, 'line_position' => $number + 1, 'line_type' => 'unsupported']);
        }
        $detail = $line[$line['DetailType']] ?? [];
        // The installed SDK represents an empty subtotal XML element as an empty string.
        if ($line['DetailType'] === 'SubTotalLineDetail' && $detail === '') {
            $detail = [];
        }
        if (! is_array($detail) || ! is_numeric($line['Amount'] ?? 0) || ! is_finite((float) ($line['Amount'] ?? 0))) {
            throw new InvoiceDataException('Invalid line detail or amount.', ['line_index' => $number]);
        }
        $itemRef = $line['DetailType'] === 'SalesItemLineDetail' ? ($detail['ItemRef'] ?? null) : null;
        $shipping = $itemRef === 'SHIPPING_ITEM_ID' || (is_array($itemRef) && ($itemRef['value'] ?? $itemRef['Value'] ?? null) === 'SHIPPING_ITEM_ID');
        $itemId = $shipping ? 'SHIPPING_ITEM_ID' : ($itemRef !== null
            ? QuickBooksReadGateway::entityRef($itemRef, ['entity' => 'Item', 'line_type' => 'SalesItemLineDetail', 'line_index' => $number]) : null);
        if ($line['DetailType'] === 'GroupLineDetail') {
            $this->validateGroup($detail, $number);
        }
        foreach (['Qty', 'UnitPrice'] as $field) {
            if (isset($detail[$field]) && (! is_numeric($detail[$field]) || ! is_finite((float) $detail[$field]))) {
                throw new InvoiceDataException('Invalid line quantity or price.', ['line_index' => $number]);
            }
        }
        QuickBooksReadGateway::ref($detail['TaxCodeRef'] ?? null);

        return ['detail' => $detail, 'item_id' => $itemId, 'shipping' => $shipping];
    }

    private function failed($id, \Throwable $e): void
    {
        $invoice = $id ? Invoice::where('quickbooks_id', $id)->first() : null;
        $message = $e instanceof InvoiceDataException ? $e->getMessage() : 'Synchronization failed; previous data preserved.';
        $context = $e instanceof InvoiceDataException ? $e->context : [];
        DB::transaction(function () use ($invoice, $message, $id, $context) {
            if ($invoice) {
                $invoice->update(['sync_status' => 'failed', 'sync_error' => $message]);
            }
            InvoiceEvent::create(['invoice_id' => $invoice?->id, 'event' => 'Invoice Sync Skipped', 'metadata' => ['quickbooks_id' => $id, 'error' => $message] + $context]);
        });
        Log::error($message, ['quickbooks_id' => $id, 'exception_type' => get_class($e)] + $context);
    }

    private function validateGroup(array $detail, int $number, int $depth = 0): void
    {
        $context = ['entity' => 'Item', 'line_type' => 'GroupLineDetail', 'line_index' => $number];
        if ($depth > 20) {
            throw new InvoiceDataException('Group nesting exceeds the supported limit.', $context);
        }
        if (array_key_exists('GroupItemRef', $detail)) {
            QuickBooksReadGateway::entityRef($detail['GroupItemRef'], $context);
        }
        $children = $detail['Line'] ?? [];
        if (! is_array($children)) {
            throw new InvoiceDataException('Malformed group lines.', $context);
        }
        foreach ($children as $child) {
            if (! is_array($child) || ! is_string($child['DetailType'] ?? null)) {
                throw new InvoiceDataException('Malformed group child.', $context);
            }
            $childDetail = $child[$child['DetailType']] ?? [];
            if (! is_array($childDetail)) {
                throw new InvoiceDataException('Malformed group child detail.', $context);
            }
            if ($child['DetailType'] === 'SalesItemLineDetail' && ($childDetail['ItemRef'] ?? null) !== null) {
                QuickBooksReadGateway::entityRef($childDetail['ItemRef'], $context);
            } elseif ($child['DetailType'] === 'GroupLineDetail') {
                $this->validateGroup($childDetail, $number, $depth + 1);
            }
        }
    }
}
