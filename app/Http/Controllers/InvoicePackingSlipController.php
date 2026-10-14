<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use Illuminate\Http\Request;

class InvoicePackingSlipController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoiceDocumentService $documents)
    {
        $pdf = $documents->packingSlip($invoice, $request->user());

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="packing-slip-'.$invoice->id.'.pdf"']);
    }
}
