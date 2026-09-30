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
                            {{ $product->serial }}
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
