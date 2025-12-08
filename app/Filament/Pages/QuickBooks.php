<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Http;
use App\Models\QuickbooksToken;
use QuickBooksOnline\API\DataService\DataService;

class QuickBooks extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'QuickBooks';
    protected static ?string $title = 'Integración QuickBooks';
    protected static ?string $navigationGroup = 'Integraciones';

    protected static string $view = 'filament.pages.quick-books';

    public array $customers = [];

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
        $token = QuickbooksToken::first();

        if (! $token) {
            $this->notify('danger', 'QuickBooks no está conectado.');
            return;
        }

        $dataService = DataService::Configure([
            'auth_mode'        => 'oauth2',
            'ClientID'         => env('QB_CLIENT_ID'),
            'ClientSecret'     => env('QB_CLIENT_SECRET'),
            'accessTokenKey'  => $token->access_token,
            'refreshTokenKey' => $token->refresh_token,
            'realmId'          => $token->realm_id,
            'scope'             => env('QB_SCOPE'),
            'baseUrl'          => env('QB_ENV', 'production'),
        ]);

        try {
            $customers = $dataService->Query("SELECT * FROM Customer MAXRESULTS 50");

            $this->customers = collect($customers)->map(function ($c) {
                return [
                    'id'    => $c->Id ?? null,
                    'name'  => $c->DisplayName ?? null,
                    'email' => $c->PrimaryEmailAddr->Address ?? null,
                    'phone' => $c->PrimaryPhone->FreeFormNumber ?? null,
                ];
            })->toArray();

            $this->notify('success', 'Customers cargados correctamente');

        } catch (\Exception $e) {
            $this->notify('danger', 'Error al consultar QuickBooks: ' . $e->getMessage());
        }
    }
}
