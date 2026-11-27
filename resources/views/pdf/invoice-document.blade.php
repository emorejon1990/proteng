<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $packing ? 'Packing Slip' : 'Invoice' }} {{ $number }}</title>
    <style>
        @page { margin: 22pt 0 24pt; }
        body { margin: 0; font-family: Helvetica, sans-serif; color: #303436; font-size: 8pt; }
        table { border-collapse: collapse; table-layout: auto; width: 100%; }
        td, th { vertical-align: top; overflow-wrap: break-word; }
        th { text-align: left; }
        .packing { color: #000; }
        .header { height: 98pt; margin: 0 48pt 0 32.25pt; font-size: 7.5pt; line-height: 11.25pt; }
        .packing .header { height: 138.5pt; margin: 0 63pt 0 60pt; line-height: 12pt; }
        .header td { padding: 0; }
        h1 { color: #0077c5; font-size: 12pt; letter-spacing: .65pt; margin: 8pt 0 2pt; }
        .packing h1 { margin: 20pt 0 6pt; }
        .contact { margin-top: 23pt; }
        .packing .contact { margin: 0; }
        .logo { width: 236pt; margin-top: 3pt; }
        .packing .logo { margin-top: 81pt; }
        .info { background: #ebf4fa; padding: 12pt 32.25pt 16pt; font-size: 9pt; line-height: 13.5pt; page-break-inside: avoid; }
        .packing .info { padding: 16pt 26.25pt 22pt 60pt; font-size: 7.5pt; line-height: 12pt; }
        .info td { width: 25%; padding: 0 8pt 0 0; }
        .info strong { display: block; margin-bottom: 2pt; }
        .packing .info strong { margin-bottom: 4.5pt; }
        .lines { margin: 18pt 45pt 0 27pt; }
        .packing .lines { margin: 15pt 48.75pt 0 60pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .lines th { font-size: 9pt; font-weight: normal; color: #000; padding: 5pt 0 9pt; }
        .lines td { padding: 5pt 0 10.75pt; line-height: 12pt; }
        .lines tr { border-bottom: .75pt solid #e3e5e8; }
        .lines td.product { font-weight: bold; padding-right: 7pt; }
        .lines td.description { padding-right: 9pt; }
        .lines .numeric { text-align: right; }
        .packing .lines tr { border: 0; }
        .packing .lines th { font-size: 7.5pt; color: #0077c5; background: #ebf4fa; padding: 2pt 0; }
        .packing .lines td { font-size: 9pt; padding: 0 0 6.75pt; }
        .packing .lines tbody tr:first-child td { padding-top: 13pt; }
        .packing .lines .product { font-weight: normal; padding-left: 9pt; padding-right: 6pt; }
        .packing .lines .numeric { padding-right: 9pt; }
        .preserve { white-space: pre-wrap; }
        .footer { margin: 23pt 45pt 0; line-height: 12pt; }
        .footer td { padding: 0; }
        .ways { font-size: 12pt; color: #0077c5; margin: 11pt 0 6pt; }
        .icons { margin-bottom: 4pt; }
        .icons img { width: 24pt; height: 15pt; vertical-align: middle; }
        .note-title { font-size: 11.25pt; font-weight: normal; margin: 22pt 0 3pt; }
        .pay { display: inline-block; background: #0077c5; color: #fff; border-radius: 18pt; width: 163pt;
               text-align: center; line-height: 36pt; text-decoration: none; margin: 36pt 0 0 -7.5pt; font-size: 12pt; }
        .totals td { border-bottom: .75pt solid #e3e5e8; padding: 9pt 0 12pt; }
        .totals .amount { text-align: right; }
        .totals .total { font-weight: bold; }
        .totals .total .amount { font-size: 12pt; }
    </style>
</head>
<body class="{{ $packing ? 'packing' : 'invoice' }}">
<div class="header">
    <table>
        <tr>
            <td style="width: {{ $packing ? '253pt' : '142pt' }}">
                <h1>{{ $packing ? 'PACKING SLIP' : 'INVOICE' }}</h1>
                <strong>{{ $config['company'] }}</strong>
                @foreach ($config['address'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($packing)
                    @foreach ($config['contact'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                @endif
            </td>
            @unless ($packing)
                <td style="width: 154pt"><div class="contact">
                    @foreach ($config['contact'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div></td>
            @endunless
            <td style="width: 236pt"><img class="logo" src="{{ $logo }}" alt="PROTENG"></td>
        </tr>
    </table>
</div>
<div class="info">
    <table>
        <tr>
            <td><strong>Bill to</strong>
                @foreach ($billTo as $line)
                    <div class="preserve">{{ $line }}</div>
                @endforeach
            </td>
            <td><strong>Ship to</strong>
                @foreach ($shipTo as $line)
                    <div class="preserve">{{ $line }}</div>
                @endforeach
            </td>
            <td><strong>Shipping info</strong>
                @if ($shipDate)<div>Ship date: {{ $shipDate }}</div>@endif
            </td>
            <td><strong>Invoice details</strong>
                @if ($number !== null)<div>Invoice no.: {{ $number }}</div>@endif
                @if (! $packing && $terms)<div>Terms: {{ $terms }}</div>@endif
                @if ($issued)<div>{{ $packing ? 'Date' : 'Invoice date' }}: {{ $issued }}</div>@endif
                @if (! $packing && $due)<div>Due date: {{ $due }}</div>@endif
            </td>
        </tr>
    </table>
</div>
@if ($lines->isNotEmpty())
    <div class="lines">
        <table>
            <thead>
                @if ($packing)
                    <tr>
                        <th class="product" style="width: 190pt">SERVICE</th>
                        <th style="width: 174pt">DESCRIPTION</th>
                        <th class="numeric" style="width: 114pt">QTY</th>
                    </tr>
                @else
                    <tr>
                        <th style="width: 18pt">#</th>
                        <th style="width: 162pt">Product or service</th>
                        <th style="width: 180pt">Description</th>
                        <th class="numeric" style="width: 49pt">Qty</th>
                        <th class="numeric" style="width: 68pt">Rate</th>
                        <th class="numeric" style="width: 63pt">Amount</th>
                    </tr>
                @endif
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        @unless ($packing)<td>{{ $loop->iteration }}.</td>@endunless
                        <td class="product preserve">{{ $line['product'] }}</td>
                        <td class="description preserve">{{ $line['description'] }}</td>
                        <td class="numeric">{{ $line['quantity'] }}</td>
                        @unless ($packing)
                            <td class="numeric">{{ $format->money($line['price'], $currency) }}</td>
                            <td class="numeric">{{ $format->money($line['amount'], $currency) }}</td>
                        @endunless
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@unless ($packing)
    <div class="footer">
        <table>
            <tr>
                <td>
                    @if ($config['checks'] || $paymentUrl)
                        <h2 class="ways">Ways to pay</h2>
                        @if ($paymentIcons)
                            <div class="icons">
                                @foreach ($paymentIcons as [$icon, $label])
                                    <img src="{{ $icon }}" alt="{{ $label }}">
                                @endforeach
                            </div>
                        @endif
                        @if ($config['checks'])
                            <div>Payment by checks:</div>
                            @foreach ($config['checks'] as $line)
                                <div>{{ $line }}</div>
                            @endforeach
                        @endif
                    @endif
                    @if ($note)
                        <h2 class="note-title">Note to customer</h2>
                        <div class="preserve">{{ $note }}</div>
                    @endif
                    @if ($paymentUrl)<a class="pay" href="{{ $paymentUrl }}">View and pay</a>@endif
                </td>
                <td style="width: 195pt">
                    <table class="totals">
                        @foreach ($totals as $label => $value)
                            @if ($value !== null)
                                <tr class="{{ $label === 'Total' ? 'total' : '' }}">
                                    <td>{{ $label }}</td><td class="amount">{{ $value }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    </div>
@endunless
</body>
</html>
