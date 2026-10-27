<?php

namespace App\Filament\Shared\Resources\WorkOrderResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Filament\Shared\Resources\WorkOrderResource;
use App\Models\Invoice;
use App\Services\InvoiceClassificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('assignInvoice')->label('Assign Invoice')->requiresConfirmation()
            ->visible(fn () => Gate::allows('assignInvoice', $this->record) && ! $this->record->invoice()->exists())
            ->modalDescription('Link an imported Invoice to this existing installation. Its status, products and history will remain unchanged.')
            ->form([Select::make('invoice_id')->label('Invoice No.')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => $this->availableInvoices()
                    ->where('invoice_number', 'like', '%'.$search.'%')->orderBy('invoice_number')->limit(25)->get()
                    ->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => $this->invoiceLabel($invoice)])->all())
                ->getOptionLabelUsing(fn ($value) => ($invoice = $this->availableInvoices()->find($value)) ? $this->invoiceLabel($invoice) : null)])
            ->action(function (array $data) {
                app(InvoiceClassificationService::class)->assignToInstallation($this->record->id, (int) $data['invoice_id'], Auth::user());
                $this->record->refresh();
            })];
    }

    private function availableInvoices(): Builder
    {
        Gate::authorize('assignInvoice', $this->record);

        return Invoice::query()->with('customer')->where('customer_id', $this->record->installation->customer_id)
            ->whereNull('work_order_id')->whereNull('operation_type')->whereNull('classified_at')
            ->where('sync_status', 'synced')->whereNull('sync_error')->whereNotNull('last_synced_at')
            ->whereNotNull('realm_id')->where('status', '!=', 'void')
            ->whereNotIn('operation_status', ['requires_review', 'sync_conflict']);
    }

    private function invoiceLabel(Invoice $invoice): string
    {
        return ($invoice->invoice_number ?? '—').' · '.$invoice->customer?->display_name.' · '.($invoice->issued_at?->format('Y-m-d') ?? '—').' · QB '.$invoice->quickbooks_id;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'), TextEntry::make('type.name'), TextEntry::make('status.name'),
            TextEntry::make('customer.display_name'),
            TextEntry::make('invoice.invoice_number')->label('Invoice'),
            TextEntry::make('installation.status'),
            TextEntry::make('installation.id')->label('View Installation')
                ->url(fn ($record) => $record->installation && InstallationResource::canAccess() && InstallationResource::canView($record->installation)
                    ? InstallationResource::getUrl('view', ['record' => $record->installation->id]) : null),
            RepeatableEntry::make('workOrderAssets')->schema([TextEntry::make('asset.name'), TextEntry::make('quantity')])->columns(2),
        ]);
    }
}
