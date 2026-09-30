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
            margin: 0 auto;
        }

        td.serial {
            width: 168px;
            height: 48px;
            /* border: 1px solid #333; */
            padding: 0;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
        }

        .barcode {
            display: block;
            max-width: 168px;
            max-height: 48px;
            margin: 0 auto;
        }

        .barcode-error {
            color: #b91c1c;
            font-size: 9px;
        }

        td.space {
            width: 30px;
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
                            <svg
                                class="barcode"
                                data-serial="{{ $product->serial }}"
                                aria-label="Barcode for {{ $product->serial }}"
                            ></svg>
                        </td>

                        @if ($column < 3)
                            <td class="space"></td>
                        @endif
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        document.querySelectorAll('.barcode').forEach((element) => {
            const serial = element.dataset.serial;

            try {
                JsBarcode(element, serial, {
                    format: 'CODE39',
                    width: 1,
                    height: 28,
                    displayValue: true,
                    font: 'monospace',
                    fontSize: 9,
                    textMargin: 1,
                    margin: 0,
                    lineColor: '#000000',
                    background: '#ffffff',
                });
            } catch (error) {
                const message = document.createElement('span');
                message.className = 'barcode-error';
                message.textContent = `Invalid CODE39: ${serial}`;
                element.replaceWith(message);
            }
        });
    </script>
</body>
</html>
