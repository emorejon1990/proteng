<?php

namespace App\Services;

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

        $qbInvoice = $ds->Add(QBInvoice::create([
            'CustomerRef' => ['value' => $customer->quickbooks_id],
            'Line' => collect($items)->map(fn ($item) => [
                'Amount' => $item['qty'] * $item['price'],
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'Qty'        => $item['qty'],
                    'UnitPrice' => $item['price'],
                ],
            ])->toArray(),
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
            ]
        );
    }

    /* ----------------- SYNC ----------------- */

    public function sync(): void
    {
        $ds = $this->qb->ds();
        $invoices = $ds->Query("SELECT * FROM Invoice");

        foreach ($invoices as $qbInvoice) {
            $customer = Customer::where(
                'quickbooks_id',
                $qbInvoice->CustomerRef ?? null
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
