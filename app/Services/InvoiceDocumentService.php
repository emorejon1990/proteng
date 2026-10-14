<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class InvoiceDocumentService
{
    public function packingSlip(Invoice $invoice, ?User $actor): string
    {
        $invoice = $invoice->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize('view', $invoice);
        abort_unless($invoice->operation_type === 'distribution' && $invoice->workOrder, 422, 'A Distribution WorkOrder is required.');
        $order = $invoice->workOrder->load('workOrderAssets.asset');

        return Pdf::loadView('pdf.invoice-packing-slip', ['invoice' => $invoice, 'order' => $order])->output();
    }
}
