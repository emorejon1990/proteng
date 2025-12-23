<?php

namespace App\Filament\Manager\Pages;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Products;
use Filament\Pages\Page;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

class ExportProducts extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.export-products';

    protected static ?string $navigationLabel = 'Export Products';

    protected static ?string $slug = 'export-products';

    protected static ?string $navigationGroup = 'Manager Tools';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole(['Admin', 'Manager']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function exportPdf()
    {
        // Traemos todos los products con sus assets y location
        $products = Products::with(['asset', 'location'])->get();
        $assets = Asset::all();
        $locations = Location::all();

        // Generamos el PDF usando una vista
        $pdf = Pdf::loadView('pdf.products', compact('products','assets','locations'));

        return response()->streamDownload(
            fn() => print($pdf->output()),
            'Products_list.pdf'
        );
    }
}
