<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use App\Models\QuickbooksToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Exception\SdkException;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\ServiceException;

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
        $token = \App\Models\QuickbooksToken::whereNotNull('realm_id')->latest()->first();

        if (! $token) {
            Notification::make()
                ->title('QuickBooks no está conectado')
                ->danger()
                ->send();
            return;
        }


        $dataService = DataService::Configure([
            'auth_mode'        => 'oauth2',
            'ClientID'         => env('QB_CLIENT_ID'),
            'ClientSecret'     => env('QB_CLIENT_SECRET'),
            'accessTokenKey'   => $token->access_token,
            'refreshTokenKey' => $token->refresh_token,
            'realmId'          => (string) $token->realm_id,
            'scope'            => env('QB_SCOPE'),
            'baseUrl'          => env('QB_ENV'), // Development
        ]);

        $dataService->throwExceptionOnError(true);

        // ✅ FORZAR MANUALMENTE EL REALM EN EL CONTEXTO (BUG DEL SDK)
        $serviceContext = $dataService->getServiceContext();
        $serviceContext->realmId = (string) $token->realm_id;

        $customers = null;
        $error = null;

        try {
            $customers = $dataService->Query("SELECT * FROM Customer MAXRESULTS 20");
            $this->customers = collect($customers)->map(function ($c) {
                return [
                    'id'    => $c->Id ?? null,
                    'name'  => $c->DisplayName ?? null,
                    'email' => $c->PrimaryEmailAddr->Address ?? null,
                    'phone' => $c->PrimaryPhone->FreeFormNumber ?? null,
                ];
            })->toArray();

            Notification::make()
                ->title('Customers cargados correctamente')
                ->success()
                ->send();
        } catch (ServiceException $e) {
            // $error = $e->getMessage();
            Notification::make()
                ->title('Error al consultar QuickBooks')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
