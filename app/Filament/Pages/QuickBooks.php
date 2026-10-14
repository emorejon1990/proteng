<?php

namespace App\Filament\Pages;

use App\Models\QuickbooksToken;
use App\Services\QuickBooksDiagnostics;
use App\Services\QuickBooksService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class QuickBooks extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'QuickBooks';

    protected static ?string $title = 'QuickBooks Integration';

    protected static ?string $navigationGroup = 'Integrations';

    protected static string $view = 'filament.pages.quick-books';

    public array $customers = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * BOTÓN: CONECTAR QUICKBOOKS
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Connect QuickBooks')
                ->color('success')
                ->url(url('/quickbooks/connect')) // tu ruta real
                ->openUrlInNewTab(),

            Action::make('loadCustomers')
                ->label('Load Customers')
                ->color('primary')
                ->action('loadCustomers'),
        ];
    }

    /**
     * FUNCIÓN QUE CARGA LOS CUSTOMERS
     */
    public function loadCustomers(): void
    {
        $token = QuickbooksToken::whereNotNull('realm_id')->latest()->first();

        if (! $token) {
            Notification::make()
                ->title('QuickBooks no está conectado')
                ->danger()
                ->send();

            return;
        }

        try {
            $dataService = app(QuickBooksService::class)->ds();
            $customers = [];
            $startPosition = 1;
            $pageSize = 1000;

            do {
                $page = $dataService->Query(
                    "SELECT * FROM Customer WHERE Active IN (true, false) ORDERBY Id STARTPOSITION {$startPosition} MAXRESULTS {$pageSize}"
                ) ?? [];

                foreach ($page as $customer) {
                    $customers[] = $customer;
                }

                $startPosition += $pageSize;
            } while (count($page) === $pageSize);

            $this->customers = collect($customers)->map(function ($c) {
                return [
                    'id' => $c->Id ?? null,
                    'name' => $c->DisplayName ?? $c->FullyQualifiedName ?? null,
                    'email' => $c->PrimaryEmailAddr->Address ?? null,
                    'phone' => $c->PrimaryPhone->FreeFormNumber ?? null,
                ];
            })->toArray();

            Notification::make()
                ->title('Customers cargados correctamente')
                ->body(count($this->customers).' customers cargados.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('load-customers', $e);
            Notification::make()
                ->title('Error al consultar QuickBooks')
                ->body('No se pudo consultar QuickBooks. Inténtalo de nuevo o contacta al administrador.')
                ->danger()
                ->send();
        }
    }
}
