<?php

namespace App\Filament\Admin\Pages;

use App\Services\AssetSyncService;
use App\Services\CustomerSyncService;
use App\Services\InvoiceSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class QuickBooksSync extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'QuickBooks Sync';

    protected static ?string $navigationGroup = 'QuickBooks';

    protected static string $view = 'filament.admin.pages.quick-books-sync';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole('Admin');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncCustomers')
                ->label('Sync Customers')
                ->action(function () {
                    app(CustomerSyncService::class)->sync();

                    Notification::make()
                        ->title('Customers synced successfully')
                        ->success()
                        ->send();
                }),

            Action::make('syncInvoices')
                ->label('Sync Invoices')
                ->action(function () {
                    abort_unless(Auth::user()?->hasRole('Admin'), 403);
                    try {
                        $summary = app(InvoiceSyncService::class)->sync();
                    } catch (\Throwable $e) {
                        // Query exceptions can contain full invoice bindings; keep diagnostics sanitized.
                        Log::error('Invoice sync action stopped', ['exception_type' => get_class($e)]);
                        Notification::make()->title('Invoice sync failed')->body('Previous data was preserved. Review sync errors and the QuickBooks connection.')->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($summary['failed'] ? 'Invoice sync partially completed' : 'Invoices synced successfully')
                        ->body($summary['synced'].' synchronized; '.$summary['failed'].' skipped. Review Invoice Sync Events.')
                        ->status($summary['failed'] ? 'warning' : 'success')
                        ->send();
                }),

            Action::make('syncProducts')
                ->label('Sync Products')
                ->action(function () {
                    app(AssetSyncService::class)->sync();

                    Notification::make()
                        ->title('Products synced successfully')
                        ->success()
                        ->send();
                }),

            Action::make('syncAll')
                ->label('Sync ALL')
                ->action(function () {
                    app(CustomerSyncService::class)->sync();
                    abort_unless(Auth::user()?->hasRole('Admin'), 403);
                    try {
                        $summary = app(InvoiceSyncService::class)->sync();
                    } catch (\Throwable $e) {
                        Log::error('Invoice sync action stopped', ['exception_type' => get_class($e)]);
                        Notification::make()->title('Invoice sync failed')->body('Previous data was preserved. Review sync errors and the QuickBooks connection.')->danger()->send();

                        return;
                    }
                    app(AssetSyncService::class)->sync();

                    Notification::make()
                        ->title($summary['failed'] ? 'Sync partially completed' : 'Customers + Invoices + Products synced successfully')
                        ->body($summary['synced'].' invoices synchronized; '.$summary['failed'].' skipped.')
                        ->status($summary['failed'] ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }
}
