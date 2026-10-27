<?php

namespace App\Filament\Admin\Pages;

use App\Services\AssetSyncService;
use App\Services\CustomerSyncService;
use App\Services\InvoiceBatchSyncService;
use App\Services\InvoiceSelectiveSyncService;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksSyncLock;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

class QuickBooksSync extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'QuickBooks Sync';

    protected static ?string $navigationGroup = 'QuickBooks';

    protected static string $view = 'filament.admin.pages.quick-books-sync';

    #[Locked]
    public array $selectiveReport = [];

    #[Locked]
    public ?string $selectiveTicket = null;

    #[Locked]
    public ?string $selectiveRunId = null;

    public function continueSelectiveDryRun(): void
    {
        abort_unless(Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer'), 403);
        if ($this->selectiveRunId === null) {
            return;
        }
        try {
            $this->selectiveReport = app(InvoiceSelectiveSyncService::class)->advanceDryRun(Auth::user(), $this->selectiveRunId);
            if ($this->selectiveReport['status'] !== 'running') {
                $this->selectiveRunId = null;
            }
        } catch (\Throwable $e) {
            $this->selectiveReport['status'] = 'stopped';
            $this->selectiveReport['errors'][] = 'Dry Run stopped. Check cache, connection and permissions; completed results remain available.';
            $this->selectiveRunId = null;
        }
    }

    #[Locked]
    public array $invoiceReport = [];

    public function mount(): void
    {
        abort_unless(static::canAccess() && ! Auth::user()->hasRole('Customer'), 403);
        $this->invoiceReport = app(InvoiceBatchSyncService::class)->current(Auth::user());
    }

    public function continueInvoiceSync(): void
    {
        abort_unless(Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer'), 403);
        if (($this->invoiceReport['status'] ?? null) !== 'running') {
            return;
        }
        try {
            $this->invoiceReport = app(InvoiceBatchSyncService::class)->advance(Auth::user(), $this->invoiceReport['run_id']);
            if ($this->invoiceReport['status'] === 'completed') {
                $summary = $this->invoiceReport['summary'];
                $partial = $summary['failed'] || $summary['review'];
                Notification::make()->title($partial ? 'Invoice sync partially completed' : 'Invoice sync completed')
                    ->body($summary['synced'].' synchronized; '.$summary['excluded'].' excluded; '.$summary['failed'].' failed; '.$summary['review'].' require review. Review Invoice Sync Events.')
                    ->status($partial ? 'warning' : 'success')->send();
            }
        } catch (\Throwable $e) {
            $this->invoiceReport['status'] = 'stopped';
            $this->invoiceReport['error'] = 'Sync paused. Another request may still be processing. Press Sync Invoices to resume safely.';
        }
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole('Admin') && ! $user->hasRole('Customer');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testThreeInvoices')
                ->label('Test 50 Pending Invoices')
                ->disabled(fn () => $this->selectiveRunId !== null)
                ->visible(fn () => Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer'))
                ->requiresConfirmation()
                ->modalHeading('Dry Run: up to 50 pending invoices')
                ->modalDescription('Select up to 50 distinct invoices with Balance > 0, including partial payments. Recheck payment before reading Items; simulate in isolated SQLite. Keep this page open; Apply is disabled.')
                ->modalSubmitActionLabel('Confirm Dry Run')
                ->action(function () {
                    $this->selectiveTicket = null;
                    try {
                        $result = app(InvoiceSelectiveSyncService::class)->dryRun(Auth::user());
                        $this->selectiveReport = $result['report'];
                        $this->selectiveRunId = $this->selectiveReport['status'] === 'running' ? $result['run_id'] : null;
                        Notification::make()->title('Pending invoices Dry Run prepared. Apply is disabled.')->info()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Selective test unavailable')->body('Check authorization or wait for the current synchronization to finish.')->danger()->send();
                    }
                }),
            Action::make('applyThreeInvoices')
                ->label('Apply 3 Invoices')
                ->visible(false)
                ->disabled()
                ->requiresConfirmation()
                ->modalHeading('Apply: 1982, 11241, 11990')
                ->modalDescription('Second confirmation: import only these three invoices into the existing database. Apply stops if the validated source or local state has changed.')
                ->form([Checkbox::make('confirmApply')->label('I reviewed the successful Dry Run and authorize applying these three invoices.')->accepted()->required()])
                ->modalSubmitActionLabel('Confirm Apply')
                ->action(function (array $data) {
                    try {
                        $this->selectiveReport = app(InvoiceSelectiveSyncService::class)->apply(Auth::user(), $this->selectiveTicket ?? '', ($data['confirmApply'] ?? false) === true);
                        Notification::make()->title($this->selectiveReport['passed'] ? 'Selective Apply completed' : 'Selective Apply stopped. Review results.')
                            ->status($this->selectiveReport['passed'] ? 'success' : 'danger')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Apply unavailable')->body('A successful, current Dry Run and a separate confirmation are required; another synchronization may be running.')->danger()->send();
                    } finally {
                        $this->selectiveTicket = null;
                    }
                }),
            Action::make('downloadSelectiveReport')
                ->label('Download Test Report')
                ->visible(fn () => Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer') && $this->selectiveReport !== [])
                ->action(function () {
                    abort_unless(Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer'), 403);
                    $json = json_encode($this->selectiveReport, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                    return response()->streamDownload(fn () => print ($json), 'quickbooks_selective_test.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
                }),
            Action::make('downloadInvoiceDebug')
                ->label('Download Invoice Debug')
                ->visible(fn () => Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer'))
                ->url(route('quickbooks.invoice-debug')),

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
                    try {
                        $this->invoiceReport = app(InvoiceBatchSyncService::class)->start(Auth::user());
                    } catch (\Throwable $e) {
                        Log::error('Invoice batch start unavailable', ['exception_type' => get_class($e)]);
                        Notification::make()->title('Invoice sync unavailable')->body('Check the connection, migrations and active synchronization. Saved progress is preserved.')->danger()->send();
                    }
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
                    app(QuickBooksSyncLock::class)->run(function () {
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
                    });
                }),
        ];
    }
}
