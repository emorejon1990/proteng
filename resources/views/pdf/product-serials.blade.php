<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Serials</title>
    <style>
        @page {
            size: 612pt 792pt;
            margin: 0;
        }

        html,
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }

        .page {
            width: 590.4pt;
            height: 720pt;
            padding: 36pt 0 0 21.6pt;
            overflow: hidden;
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto;
        }

        table {
            width: 590.4pt;
            table-layout: fixed;
            border-collapse: collapse;
            border-spacing: 0;
            margin: 0;
        }

        tr {
            height: 36pt;
            page-break-inside: avoid;
        }

        td.slot {
            width: 147.6pt;
            height: 36pt;
            padding: 0;
            vertical-align: top;
            overflow: hidden;
        }

        .serial {
            width: 126pt;
            height: 36pt;
            padding: 0;
            text-align: center;
            overflow: hidden;
        }

        .server-barcode {
            display: block;
            width: 113.04pt;
            height: 27.36pt;
            margin: 0 auto;
        }

        .serial-value {
            height: 8.64pt;
            font-family: monospace;
            font-size: 8pt;
            line-height: 8.64pt;
            text-align: center;
        }

    </style>
</head>
<body>
    @foreach ($products->chunk(20) as $pageProducts)
        <div class="page">
            <table>
                <colgroup>
                    <col style="width: 147.6pt">
                    <col style="width: 147.6pt">
                    <col style="width: 147.6pt">
                    <col style="width: 147.6pt">
                </colgroup>
                <tbody>
                    @foreach ($pageProducts as $product)
                        <tr>
                            @for ($column = 0; $column < 4; $column++)
                                <td class="slot">
                                    <div class="serial">
                                        <img
                                            class="server-barcode"
                                            src="{{ $barcodes[$product->getKey()] }}"
                                            alt="Barcode for {{ $product->serial }}"
                                        >
                                        <div class="serial-value">{{ $product->serial }}</div>
                                    </div>
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

</body>
</html>
