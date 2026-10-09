<?php

namespace App\Filament\Shared\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ExportSerials extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static string $view = 'filament.pages.export-serials';

    protected static ?string $navigationLabel = 'Export Serials';

    protected static ?string $title = 'Export Serials';

    protected static ?string $slug = 'export-serials';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(['Admin', 'Manager']) ?? false;
    }
}
