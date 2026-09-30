<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        return view('pdf.product-serials', compact('products'));
    }
}
