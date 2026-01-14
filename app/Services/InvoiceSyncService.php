<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\QuickBooksService;
use QuickBooksOnline\API\Facades\Invoice as QBInvoice;

class InvoiceSyncService
{
    public function __construct(
        protected QuickBooksService $qb
    ) {}

    /* ----------------- CREATE ----------------- */

    public function create(Customer $customer, array $items): Invoice
    {
        $ds = $this->qb->ds();

        $lines = collect($items)
            ->map(function ($item) {
                $asset = Asset::find($item['asset_id'] ?? null);

                if (! $asset || ! $asset->quickbooks_id) {
                    throw new \RuntimeException('Cada item debe tener un asset con quickbooks_id.');
                }

                return [
                    'Amount' => $item['qty'] * $item['price'],
                    'DetailType' => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        'ItemRef' => [
                            'value' => $asset->quickbooks_id,
                            'name' => $asset->name,
                        ],
                        'Qty' => $item['qty'],
                        'UnitPrice' => $item['price'],
                    ],
                ];
            })
            ->values()
            ->toArray();

        $qbInvoice = $ds->Add(QBInvoice::create([
            'CustomerRef' => ['value' => $customer->quickbooks_id],
            'Line' => $lines,
        ]));

        return Invoice::updateOrCreate(
            ['quickbooks_id' => $qbInvoice->Id],
            [
                'customer_id'    => $customer->id,
                'invoice_number' => $qbInvoice->DocNumber ?? null,
                'total'          => $qbInvoice->TotalAmt ?? 0,
                'balance'        => $qbInvoice->Balance ?? 0,
                'status'         => ($qbInvoice->Balance ?? 0) > 0 ? 'open' : 'paid',
                'issued_at'      => $qbInvoice->TxnDate ?? null,
                'due_at'         => $qbInvoice->DueDate ?? null,
                'metadata'       => ['items' => $items],
            ]
        );
    }

    /* ----------------- SYNC ----------------- */

    public function sync(): void
    {
        $ds = $this->qb->ds();
        $invoices = $ds->Query("SELECT * FROM Invoice");

        foreach ($invoices as $qbInvoice) {
            $customerRef = $qbInvoice->CustomerRef->value ?? null;

            $customer = Customer::where(
                'quickbooks_id',
                $customerRef
            )->first();

            if (! $customer) continue;

            Invoice::updateOrCreate(
                ['quickbooks_id' => $qbInvoice->Id],
                [
                    'customer_id'    => $customer->id,
                    'invoice_number' => $qbInvoice->DocNumber ?? null,
                    'total'          => $qbInvoice->TotalAmt ?? 0,
                    'balance'        => $qbInvoice->Balance ?? 0,
                    'status'         => ($qbInvoice->Balance ?? 0) > 0 ? 'open' : 'paid',
                    'issued_at'      => $qbInvoice->TxnDate ?? null,
                    'due_at'         => $qbInvoice->DueDate ?? null,
                ]
            );
        }
    }
}
