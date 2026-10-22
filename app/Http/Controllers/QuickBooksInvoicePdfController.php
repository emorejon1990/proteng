<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\QuickBooksReadGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QuickBooksInvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, QuickBooksReadGateway $gateway)
    {
        if ($request->query->has('work_order')) {
            $data = $request->validate(['work_order' => ['required', 'integer', 'min:1']]);
            $workOrder = WorkOrder::findOrFail($data['work_order']);
            Gate::authorize('printFromDistribution', [$invoice, $workOrder]);
        } else {
            // Existing invoice screens retain their original financial/ownership authorization.
            Gate::authorize('view', $invoice);
        }
        try {
            $pdf = $gateway->pdf($invoice);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Unable to download invoice PDF. Check the QuickBooks connection and retry.');
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="invoice-'.$invoice->id.'.pdf"']);
    }
}
