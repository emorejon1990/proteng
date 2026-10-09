<?php

namespace App\Http\Controllers;

use App\Models\Serial;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode39;

class SerialExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->hasRole(['Admin', 'Manager']), 403);

        $quantity = (int) $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ])['quantity'];

        $html = DB::transaction(function () use ($quantity): string {
            $products = Serial::query()
                ->whereIn('status', [Serial::STATUS_RESERVED, Serial::STATUS_FREE])
                ->where('printed', false)
                ->orderByDesc('status')
                ->orderBy('id')
                ->limit($quantity)
                ->lockForUpdate()
                ->get();

            if ($products->count() !== $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "No hay suficientes seriales pendientes de imprimir. Disponibles: {$products->count()}.",
                ]);
            }

            $type = new TypeCode39;
            $renderer = (new SvgRenderer)
                ->setSvgType(SvgRenderer::TYPE_SVG_INLINE)
                ->setBackgroundColor([255, 255, 255]);

            $barcodes = $products->mapWithKeys(function (Serial $serial) use ($type, $renderer): array {
                $svg = $renderer->render($type->getBarcode($serial->serial), 325, 57);

                return [$serial->getKey() => 'data:image/svg+xml;base64,'.base64_encode($svg)];
            });

            // Render successfully before marking the selected, locked serials.
            $html = view('pdf.product-serials', compact('products', 'barcodes'))->render();
            Serial::whereKey($products->modelKeys())->update(['printed' => true]);

            return $html;
        });

        return response($html)->header('Cache-Control', 'no-store, private');
    }
}
