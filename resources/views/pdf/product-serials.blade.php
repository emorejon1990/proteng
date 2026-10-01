<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Serials</title>
    <style>
        @page {
            size: 612pt 792pt;
            margin: 38.16pt 19.8pt 32.4pt 21.24pt;
        }

        html,
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }

        table {
            width: 559.08pt;
            table-layout: fixed;
            border-collapse: collapse;
            border-spacing: 0;
            margin: 0;
        }

        tr {
            height: 36pt;
            page-break-inside: avoid;
        }

        td.serial {
            width: 126pt;
            height: 36pt;
            /* border: 1px solid #333; */
            padding: 0;
            text-align: center;
            vertical-align: middle;
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

        td.space {
            width: 18.36pt;
            border: 0;
            padding: 0;
        }
    </style>
</head>
<body>
    <table>
        <colgroup>
            <col style="width: 126pt">
            <col style="width: 18.36pt">
            <col style="width: 126pt">
            <col style="width: 18.36pt">
            <col style="width: 126pt">
            <col style="width: 18.36pt">
            <col style="width: 126pt">
        </colgroup>
        <tbody>
            @foreach ($products as $product)
                <tr>
                    @for ($column = 0; $column < 4; $column++)
                        <td class="serial">
                            <img
                                class="server-barcode"
                                src="{{ $barcodes[$product->getKey()] }}"
                                alt="Barcode for {{ $product->serial }}"
                            >
                            <div class="serial-value">{{ $product->serial }}</div>
                        </td>

                        @if ($column < 3)
                            <td class="space"></td>
                        @endif
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

</body>
</html>
