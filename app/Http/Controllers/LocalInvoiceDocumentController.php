<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LocalInvoiceDocumentController extends Controller
{
    public function invoice(Request $request, Invoice $invoice, InvoiceDocumentRenderer $documents): Response
    {
        return $this->download($request, $invoice, $documents, false);
    }

    public function packingSlip(Request $request, Invoice $invoice, InvoiceDocumentRenderer $documents): Response
    {
        return $this->download($request, $invoice, $documents, true);
    }

    private function download(Request $request, Invoice $invoice, InvoiceDocumentRenderer $documents, bool $packing): Response
    {
        if ($request->query->has('work_order')) {
            $data = $request->validate(['work_order' => ['required', 'integer', 'min:1']]);
            Gate::authorize('printFromDistribution', [$invoice, WorkOrder::findOrFail($data['work_order'])]);
        } else {
            Gate::authorize('view', $invoice);
        }

        $type = $packing ? 'packing-slip' : 'invoice';
        $number = preg_replace('/[^a-zA-Z0-9_-]/', '-', $invoice->invoice_number ?? '') ?: $invoice->id;

        return response($documents->render($invoice, $packing), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$type.'-'.$number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
