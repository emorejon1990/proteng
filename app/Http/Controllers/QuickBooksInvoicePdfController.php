<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class QuickBooksInvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice)
    {
        // ✅ Seguridad: el invoice debe pertenecer al customer autenticado (si es Customer)
        $user = $request->user();

        if ($user?->customer) {
            abort_if($invoice->customer_id !== $user->customer->id, 403);
        }

        // Aquí asumo que guardas tokens en QuickbooksToken (como hicimos antes)
        $token = \App\Models\QuickbooksToken::query()->first(); // o ->where('company_id', ...)

        abort_if(! $token?->access_token || ! $token?->realm_id, 500, 'QuickBooks token not found.');

        $base = rtrim(config('services.quickbooks.base_url', env('QB_ENV')), '/'); // ej: https://sandbox-quickbooks.api.intuit.com
        $realmId = $token->realm_id;

        abort_if(! $invoice->quickbooks_id, 404, 'Invoice not linked to QuickBooks.');

        // Endpoint oficial para PDF:
        // GET /v3/company/{realmId}/invoice/{invoiceId}/pdf
        $url = "{$base}/v3/company/{$realmId}/invoice/{$invoice->quickbooks_id}/pdf";

        $response = Http::withToken($token->access_token)
            ->withHeaders([
                'Accept' => 'application/pdf',
            ])
            ->get($url);

        if (! $response->successful()) {
            abort($response->status(), 'Unable to download PDF from QuickBooks.');
        }

        $filename = 'invoice-' . ($invoice->invoice_number ?: $invoice->quickbooks_id) . '.pdf';

        return response($response->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
