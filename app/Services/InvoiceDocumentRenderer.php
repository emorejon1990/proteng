<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class InvoiceDocumentRenderer
{
    public function render(Invoice $invoice, bool $packing): string
    {
        $invoice->loadMissing(['customer', 'lines']);
        $raw = $invoice->raw_payload ?? [];
        $customer = $this->referenceName($raw['CustomerRef'] ?? null) ?? $invoice->customer?->display_name;
        $billTo = $this->address($invoice->billing_address ?? $raw['BillAddr'] ?? [], $customer);
        $shipTo = $this->address($invoice->shipping_address ?? $raw['ShipAddr'] ?? [], $customer);
        $shipDate = $this->date($raw['ShipDate'] ?? null);
        $number = $invoice->invoice_number ?? $raw['DocNumber'] ?? null;
        $issued = $invoice->issued_at?->format('m/d/Y');
        $due = $invoice->due_at?->format('m/d/Y');
        $terms = $this->referenceName($raw['SalesTermRef'] ?? null);
        $config = config('invoice_documents');
        $logo = $this->image('logo');
        $lines = $invoice->lines->flatMap(fn (InvoiceLine $line) => $this->documentLines($line))->values();
        $data = compact('packing', 'billTo', 'shipTo', 'shipDate', 'number', 'issued', 'due', 'terms', 'config', 'logo', 'lines');

        if (! $packing) {
            $currency = $invoice->currency_code;
            $subtotal = $invoice->lines->last(fn (InvoiceLine $line) => $line->detail_type === 'SubTotalLineDetail')?->amount;
            $tax = $invoice->tax_total;
            $discount = $invoice->discount_total;
            $totals = [
                'Subtotal' => ($tax != 0 || $discount != 0) ? $this->money($subtotal, $currency) : null,
                'Tax' => $tax != 0 ? $this->money($tax, $currency) : null,
                'Discount' => $discount != 0 ? $this->money($discount, $currency) : null,
                'Total' => $this->money($invoice->total, $currency),
            ];
            $note = $raw['CustomerMemo']['value'] ?? null;
            $paymentUrl = $this->paymentUrl($raw['InvoiceLink'] ?? null);
            $paymentIcons = $paymentUrl ? $this->paymentIcons($raw) : [];
            $data += compact('currency', 'totals', 'note', 'paymentUrl', 'paymentIcons');
        }

        return Pdf::loadView('pdf.invoice-document', $data + ['format' => $this])
            ->setPaper('letter')->setOption('isRemoteEnabled', false)->output();
    }

    private function documentLines(InvoiceLine $line): array
    {
        if (in_array($line->detail_type, ['SubTotalLineDetail', 'DiscountLineDetail', 'TaxLineDetail'], true)) {
            return [];
        }

        $detail = $line->raw_payload[$line->detail_type] ?? [];
        $children = $line->detail_type === 'GroupLineDetail' ? ($detail['Line'] ?? []) : [];
        $quantity = $line->quantity ?? $detail['Quantity'] ?? null;
        $rows = [[
            'product' => $this->referenceName($detail['ItemRef'] ?? $detail['GroupItemRef'] ?? null),
            'description' => $line->description,
            'quantity' => $this->quantity($quantity),
            'price' => $line->unit_price,
            'amount' => $children || $line->detail_type === 'DescriptionOnly' ? null : $line->amount,
        ]];
        foreach ($children as $child) {
            $childDetail = $child[$child['DetailType']] ?? [];
            array_push($rows, ...$this->documentLines(new InvoiceLine([
                'detail_type' => $child['DetailType'],
                'description' => $child['Description'] ?? null,
                'quantity' => $childDetail['Qty'] ?? null,
                'unit_price' => $childDetail['UnitPrice'] ?? null,
                'amount' => $child['Amount'] ?? null,
                'raw_payload' => $child,
            ])));
        }

        return $rows;
    }

    private function referenceName(mixed $reference): ?string
    {
        $name = $reference['name'] ?? $reference['Name'] ?? null;

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    private function address(array $address, ?string $customer): array
    {
        $locality = implode(', ', array_filter([
            $address['City'] ?? null,
            trim(($address['CountrySubDivisionCode'] ?? '').' '.($address['PostalCode'] ?? '')),
        ]));
        $lines = array_values(array_filter([
            $address['Line1'] ?? null, $address['Line2'] ?? null, $address['Line3'] ?? null,
            $address['Line4'] ?? null, $address['Line5'] ?? null, $locality, $address['Country'] ?? null,
        ]));
        if ($customer && strcasecmp($customer, $lines[0] ?? '') !== 0) {
            array_unshift($lines, $customer);
        }

        return $lines;
    }

    private function date(?string $value): ?string
    {
        if ($value && Carbon::canBeCreatedFromFormat($value, 'Y-m-d')) {
            return Carbon::createFromFormat('Y-m-d', $value)->format('m/d/Y');
        }

        return null;
    }

    private function image(string $name): string
    {
        return 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path("pdf/invoice/{$name}.png")));
    }

    private function paymentUrl(mixed $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user'])
            && in_array(strtolower($parts['host'] ?? ''), ['connect.intuit.com', 'qbo.intuit.com'], true)
            ? $url : null;
    }

    private function paymentIcons(array $raw): array
    {
        $icons = [];
        if ($raw['AllowOnlineCreditCardPayment'] ?? false) {
            $icons = ['Visa', 'Mastercard', 'Discover', 'American Express'];
        }
        if ($raw['AllowOnlineACHPayment'] ?? false) {
            $icons[4] = 'Bank';
        }
        if ($raw['AllowOnlinePayPalPayment'] ?? false) {
            $icons[5] = 'PayPal';
        }
        if ($raw['AllowOnlineVenmoPayment'] ?? false) {
            $icons[6] = 'Venmo';
        }

        return array_map(fn ($index, $label) => [$this->image("pay-{$index}"), $label], array_keys($icons), $icons);
    }

    private function quantity(?string $value): ?string
    {
        return $value !== null && str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public function money(?string $value, ?string $currency): ?string
    {
        if ($value === null) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        [$whole, $decimal] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $symbol = $currency === 'USD' ? '$' : ($currency ? $currency.' ' : '');

        return ($negative ? '-' : '').$symbol.$whole.'.'.str_pad(rtrim($decimal, '0'), 2, '0');
    }
}
