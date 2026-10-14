<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\DataService\DataService;
use SplObjectStorage;

class QuickBooksInvoiceDebugService
{
    public const INVOICE_IDS = ['1982', '11241', '11990'];

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(private QuickBooksService $qb, private QuickBooksReadGateway $gateway) {}

    public function capture(): array
    {
        $raw = [];
        $sdk = [];
        $metadata = ['invoice_ids' => self::INVOICE_IDS];
        try {
            $realm = $this->gateway->realm();
            $ds = $this->qb->ds($realm);
            $base = rtrim($ds->getServiceContext()->getBaseURL(), '/');
            $environment = match ($base) {
                'https://quickbooks.api.intuit.com/v3' => 'production',
                'https://sandbox-quickbooks.api.intuit.com/v3' => 'sandbox',
                default => throw new \RuntimeException('Unexpected QuickBooks endpoint.'),
            };
            $minor = $ds->getMinorVersion();
            $token = $ds->getOAuth2LoginHelper()->getAccessToken()->getAccessToken();
            $this->assertConnection($ds, $realm, $base);
            $metadata += ['realm_id' => $realm, 'environment' => $environment, 'minor_version' => $minor];
        } catch (\Throwable $e) {
            foreach (self::INVOICE_IDS as $id) {
                $raw[$id] = $sdk[$id] = ['error' => 'Unable to prepare the configured QuickBooks connection.'];
            }

            return $this->documents($metadata, $raw, $sdk);
        }

        foreach (self::INVOICE_IDS as $id) {
            try {
                $this->assertConnection($ds, $realm, $base);
                // Capture bytes before JSON decoding or SDK transformations. Never follow redirects with a token.
                $response = Http::withToken($token)->acceptJson()->withoutRedirecting()
                    ->connectTimeout(10)->timeout(30)
                    ->get($base.'/company/'.rawurlencode($realm).'/invoice/'.$id,
                        $minor === null || $minor === '' ? [] : ['minorversion' => $minor]);
                $raw[$id] = ['http_status' => $response->status(), 'body_json' => $response->body()];
                if (! $response->successful()) {
                    $raw[$id]['error'] = 'QuickBooks HTTP request failed.';
                }
                // A non-UTF-8 error body must not prevent exporting the other invoices.
                json_encode($raw[$id], self::JSON_FLAGS);
            } catch (\Throwable $e) {
                $raw[$id] = ['error' => 'Invoice HTTP capture failed. Check connection and configured company.'];
            }

            try {
                $this->assertConnection($ds, $realm, $base);
                $invoice = $ds->FindById('Invoice', $id);
                if ($ds->getLastError() || ! $invoice instanceof IPPInvoice || (string) $invoice->Id !== $id) {
                    throw new \RuntimeException('Invalid SDK invoice response.');
                }
                $capture = ['value' => self::describe($invoice, new SplObjectStorage)];
                json_encode($capture, self::JSON_FLAGS);
                $sdk[$id] = $capture;
            } catch (\Throwable $e) {
                // SDK exceptions may contain HTTP headers/tokens; never export their messages or traces.
                $sdk[$id] = ['error' => 'Invoice SDK capture failed. Check connection, permissions and invoice availability.'];
            }
        }

        return $this->documents($metadata, $raw, $sdk);
    }

    private function assertConnection(DataService $ds, string $realm, string $base): void
    {
        $context = $ds->getServiceContext();
        if ($this->gateway->realm() !== $realm || (string) $context->realmId !== $realm
            || rtrim($context->getBaseURL(), '/') !== $base) {
            throw new \RuntimeException('QuickBooks connection changed.');
        }
    }

    private function documents(array $metadata, array $raw, array $sdk): array
    {
        // body_json stores each exact HTTP body as a JSON string; decode it separately when comparing.
        return [
            'quickbooks_raw.json' => json_encode(['metadata' => $metadata, 'responses' => (object) $raw], self::JSON_FLAGS),
            'quickbooks_sdk.json' => json_encode(['metadata' => $metadata, 'responses' => (object) $sdk], self::JSON_FLAGS),
        ];
    }

    private static function describe(mixed $value, SplObjectStorage $seen): array
    {
        if (is_object($value)) {
            if ($seen->contains($value)) {
                return ['type' => 'object-reference', 'object_id' => $seen[$value]];
            }
            $seen[$value] = count($seen) + 1;
            $properties = [];
            foreach (get_mangled_object_vars($value) as $key => $entry) {
                $properties[$key] = self::describe($entry, $seen);
            }

            return ['type' => 'object', 'class' => get_class($value), 'object_id' => $seen[$value], 'properties' => (object) $properties];
        }
        if (is_array($value)) {
            $entries = [];
            foreach ($value as $key => $entry) {
                $entries[] = ['key' => self::describe($key, $seen), 'value' => self::describe($entry, $seen)];
            }

            return ['type' => 'array', 'entries' => $entries];
        }

        return ['type' => gettype($value), 'value' => $value];
    }
}
