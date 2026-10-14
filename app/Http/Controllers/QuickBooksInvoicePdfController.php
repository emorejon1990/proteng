<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\QuickBooksReadGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QuickBooksInvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, QuickBooksReadGateway $gateway)
    {
        Gate::authorize('view', $invoice);
        try {
            $pdf = $gateway->pdf($invoice);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Unable to download invoice PDF. Check the QuickBooks connection and retry.');
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="invoice-'.$invoice->id.'.pdf"']);
    }
}
