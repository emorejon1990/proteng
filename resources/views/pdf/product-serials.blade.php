<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <style>
        /* Cada página impresa será una etiqueta */
        @page {
            size: 90.3mm 29.0mm;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
        }

        .label {
            width: 90.3mm;
            height: 29.0mm;

            margin: 0;
            padding: 0.8mm 1mm;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;

            overflow: hidden;

            break-inside: avoid;
            page-break-inside: avoid;
            break-after: page;
            page-break-after: always;
        }

        .label:last-child {
            break-after: auto;
            page-break-after: auto;
        }

        .barcode {
            width: 86mm;
            height: 15mm;
            flex-shrink: 0;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        .barcode img,
        .barcode svg {
            display: block;
            max-width: 86mm;
            max-height: 15mm;
        }

        .serial {
            width: 100%;
            margin-top: 0.5mm;
            flex-shrink: 0;

            font-size: 7pt;
            line-height: 1;
            font-weight: bold;
            text-align: center;

            white-space: nowrap;
        }

        @media screen {
            body {
                background: #eee;
            }

            .label {
                background: white;
                margin: 5mm auto;
                outline: 1px solid #aaa;
            }
        }

        @media print {
            html,
            body {
                width: 38mm;
            }

            body {
                background: white;
            }

            .label {
                margin: 0;
                outline: none;
            }
        }
    </style>
</head>

<body>

    @foreach ($products as $product)

        @for ($copy = 0; $copy < 4; $copy++)

            <div class="label">

                <div class="barcode">
                    <img
                        src="{{ $barcodes[$product->getKey()] }}"
                        alt="{{ $product->serial }}"
                    >
                </div>

                <div class="serial">
                    {{ $product->serial }}
                </div>

            </div>

        @endfor

    @endforeach

</body>
</html>
