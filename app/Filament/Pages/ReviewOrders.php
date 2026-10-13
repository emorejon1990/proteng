<?php

namespace App\Filament\Pages;

use App\Services\ProductionCatalog;
use App\Services\ReviewWorkOrderService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ReviewOrders extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?string $navigationLabel = 'Órdenes Review';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.review-orders';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasRole(['Admin', 'Manager']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user(), true);
    }

    public function closeReviewAction(): Action
    {
        return Action::make('closeReview')->label('Cerrar y enviar a aprobación')->requiresConfirmation()
            ->modalHeading('Cerrar recepción de productos')
            ->modalDescription('La orden dejará de recibir tubos y quedará pendiente de aprobación.')
            ->action(fn (array $arguments) => app(ReviewWorkOrderService::class)->close((int) ($arguments['orderId'] ?? 0), Auth::user()));
    }

    public function approveReviewAction(): Action
    {
        return Action::make('approveReview')->label('Aprobar')->requiresConfirmation()
            ->action(fn (array $arguments) => app(ReviewWorkOrderService::class)->approve((int) ($arguments['orderId'] ?? 0), Auth::user()));
    }

    public function startReviewAction(): Action
    {
        return Action::make('startReview')->label('Iniciar')->requiresConfirmation()
            ->action(fn (array $arguments) => app(ReviewWorkOrderService::class)->start((int) ($arguments['orderId'] ?? 0), Auth::user()));
    }

    public function incorporateRejectionAction(): Action
    {
        return Action::make('incorporateRejection')->label('Incorporar a Review')->requiresConfirmation()
            ->action(fn (array $arguments) => app(ReviewWorkOrderService::class)->incorporatePending((int) ($arguments['cycleId'] ?? 0), Auth::user()));
    }

    protected function getViewData(): array
    {
        $service = app(ReviewWorkOrderService::class);
        $service->authorize(Auth::user(), true);
        try {
            $catalog = app(ProductionCatalog::class);
            $newStatus = $catalog->id('new_order_status');
            $approvedStatus = $catalog->id('approved_order_status');

            return [
                'reviewOrders' => $service->orders()->with('status', 'productAssociations.product.asset', 'productAssociations.product.productionWorkOrder')->orderByDesc('id')->get(),
                'pendingRejections' => $service->pendingRejections()->with('product.asset')->orderBy('id')->get(),
                'newStatus' => $newStatus, 'approvedStatus' => $approvedStatus, 'availabilityError' => null,
            ];
        } catch (ValidationException $exception) {
            return ['reviewOrders' => collect(), 'pendingRejections' => collect(), 'newStatus' => null,
                'approvedStatus' => null, 'availabilityError' => collect($exception->errors())->flatten()->first()];
        }
    }
}
