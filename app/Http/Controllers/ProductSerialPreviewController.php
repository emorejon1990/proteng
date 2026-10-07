<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode39;

class ProductSerialPreviewController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(
            $request->user()?->hasRole(['Admin', 'Manager']),
            403
        );

        $productIds = collect($request->query('products', []))
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        abort_if($productIds->isEmpty(), 404);

        $products = Products::query()
            ->whereKey($productIds)
            ->orderBy('serial')
            ->get();

        abort_if($products->isEmpty(), 404);

        $invalidSerial = $products->first(
            fn (Products $product) => preg_match(
                '/^[0-9A-Z\-. $\/+%]+$/',
                (string) $product->serial
            ) !== 1
        );

        abort_if(
            $invalidSerial,
            422,
            "Serial {$invalidSerial?->serial} contains unsupported Code 39 characters."
        );

        $type = new TypeCode39();
        $renderer = (new SvgRenderer())
            ->setSvgType(SvgRenderer::TYPE_SVG_INLINE)
            ->setBackgroundColor([255, 255, 255]);

        $barcodes = $products->mapWithKeys(function (Products $product) use ($type, $renderer): array {
            $barcode = $type->getBarcode((string) $product->serial);
            // $svg = $renderer->render($barcode, 129, 27);
            $svg = $renderer->render($barcode, 325, 57);

            return [
                $product->getKey() => 'data:image/svg+xml;base64,' . base64_encode($svg),
            ];
        });

        return view('pdf.product-serials', compact('products', 'barcodes'));
    }
}
