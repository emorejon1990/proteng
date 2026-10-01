<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Serials</title>
    <style>
        @page {
            size: letter portrait;
            margin: 27px;
        }

        body {
            font-family: sans-serif;
            margin: 0;
        }

        table {
            width: 762px;
            table-layout: fixed;
            border-collapse: collapse;
            margin: 14mm 13mm;
            /* margin-top: 14mm; */

        }

        td.serial {
            width: 44mm;
            height: 13mm;
            /* border: 1px solid #333; */
            padding: 0;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
        }

        .server-barcode {
            display: block;
            width: 40mm;
            height: 7mm;
            margin: 0 auto;
        }

        .serial-value {
            height: 2mm;
            font-family: monospace;
            font-size: 2mm;
            line-height: 2mm;
            text-align: center;
        }

        td.space {
            width: 13mm;
            border: 0;
            padding: 0;
        }
    </style>
</head>
<body>
    <table>
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
