<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use App\Services\InvoiceSyncService;
use Illuminate\Support\Facades\Auth;
use App\Services\CustomerSyncService;
use Filament\Notifications\Notification;

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
                    app(InvoiceSyncService::class)->sync();

                    Notification::make()
                        ->title('Invoices synced successfully')
                        ->success()
                        ->send();
                }),

            Action::make('syncAll')
                ->label('Sync ALL')
                ->action(function () {
                    app(CustomerSyncService::class)->sync();
                    app(InvoiceSyncService::class)->sync();

                    Notification::make()
                        ->title('Customers + Invoices synced successfully')
                        ->success()
                        ->send();
                }),
        ];
    }
}
