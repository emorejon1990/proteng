<!DOCTYPE html>
<html>
<head>
    <title>Assets PDF</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 80px 20px 20px 20px; } /* padding top para header */

        /* Cabecera fija */
        header {
            position: fixed;
            top: 0;
            left: 0;
            /* right: 0; */
            height: 50px;
            text-align: center;
            /*border-bottom: 1px solid #333;*/
        }

        header img {
            width: 120px;
            display: block;
            margin: 10px auto;
        }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #333; padding: 5px; text-align: left; }

        h2 {
            /* page-break-before: always; /* cada location inicia nueva página */
            margin-top: 0;
        }
        .location:first-of-type h2 { page-break-before: auto; } /*primera location sin salto*/
    </style>
</head>
<body>
    <!-- Cabecera fija que se repite en todas las páginas -->
    <header>
        <img src="file://{{ public_path('Proteng_Logo.png') }}" alt="Proteng Logo">
    </header>

    @foreach($locations as $location)
        <div class="location">
            <h2>{{ $location->name }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assets as $asset)
                        @php
                            $cant = 0;
                        @endphp
                        @foreach ($products as $product)
                            @if ($product->asset_id == $asset->id && $product->location_id == $location->id)
                                @php $cant++; @endphp
                            @endif
                        @endforeach
                        <tr>
                            <td>{{ $asset->name }}</td>
                            <td>{{ $cant }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>

