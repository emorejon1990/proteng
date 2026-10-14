<?php

namespace App\Http\Controllers;

use App\Services\QuickBooksInvoiceDebugService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class QuickBooksInvoiceDebugController extends Controller
{
    public function __invoke(Request $request, QuickBooksInvoiceDebugService $debug): StreamedResponse
    {
        abort_unless($request->user()?->hasRole('Admin') && ! $request->user()->hasRole('Customer'), 403);
        $documents = $debug->capture();

        return response()->streamDownload(function () use ($documents) {
            $path = tempnam(sys_get_temp_dir(), 'qb-invoice-debug-');
            if ($path === false) {
                throw new \RuntimeException('Unable to create private debug archive.');
            }
            $zip = new ZipArchive;
            $opened = false;
            try {
                if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Unable to open debug archive.');
                }
                $opened = true;
                foreach ($documents as $name => $json) {
                    if (! $zip->addFromString($name, $json)) {
                        throw new \RuntimeException('Unable to write debug archive.');
                    }
                }
                if (! $zip->close()) {
                    throw new \RuntimeException('Unable to finish debug archive.');
                }
                $opened = false;
                readfile($path);
            } finally {
                try {
                    if ($opened) {
                        $zip->close();
                    }
                } finally {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }, 'quickbooks_invoice_debug.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
