<?php

namespace App\Filament\Admin\Pages;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Status;
use App\Models\User;
use App\Services\ExistingProductsRefillService;
use App\Services\ProductionCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class RefillProd extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string $view = 'filament.pages.refill-prod';

    protected static ?string $navigationGroup = 'Admin Tools';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public string $serialInput = '';

    #[Locked]
    public ?int $operationId = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer');
    }

    public function mount(): void
    {
        $service = $this->service();
        $operation = $service->operations(Auth::user())->where('active_user_id', Auth::id())->first()
            ?? $service->operations(Auth::user())->first();
        if ($operation) {
            $this->operationId = $operation->id;
        }
        $this->form->fill($operation?->data ?? $this->defaults());
    }

    public function form(Form $form): Form
    {
        $catalog = app(ProductionCatalog::class);

        return $form->schema([
            Section::make('Products')->description('Existing finished products enter Stock immediately after each valid scan. Start a new operation to change the common form.')
                ->disabled(fn () => $this->operationId !== null)->schema([
                    Select::make('asset_id')->label('Type')->options(Asset::query()->pluck('name', 'id'))->searchable()->required(),
                    Select::make('assambly_by')->label('Assembler')->options(User::query()->pluck('name', 'id'))->searchable()->required(),
                    Checkbox::make('assambled')->label('Assembled')->accepted(),
                    Select::make('fill_by')->label('Filler')->options(User::query()->pluck('name', 'id'))->searchable()->required(),
                    Checkbox::make('filled')->label('Filled')->accepted(),
                    Select::make('quality_by')->label('Quality Checker')->options(User::query()->pluck('name', 'id'))->searchable()->required(),
                    Checkbox::make('qualified')->label('Qualified')->accepted(),
                    Select::make('status_id')->label('Status')->options(Status::whereKey($catalog->id('ready_product_status'))->pluck('name', 'id'))->required(),
                    Select::make('location_id')->label('Location')->options(Location::whereKey($catalog->id('stock_location'))->pluck('name', 'id'))->required(),
                    Select::make('weight')->label('Weight')->options(Asset::query()->pluck('weight', 'weight'))->searchable()->required(),
                ])->columns(2),
        ])->statePath('data');
    }

    public function scan(): void
    {
        $service = $this->service();
        $data = $this->operationId ? $service->operation($this->operationId, Auth::user())->data : $this->form->getState();
        try {
            $entry = $service->scan($data, $this->serialInput, $this->operationId, Auth::user());
            $this->operationId = $entry->refill_operation_id;
            $this->serialInput = '';
            unset($this->entries, $this->operationClosed);
            Notification::make()->title('Product registered in Stock')->success()->send();
            $this->dispatch('refill-scan-focus');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Refill scan failed', ['exception_type' => get_class($e)]);
            Notification::make()->title('Product could not be registered')->body('No incomplete product was saved and the serial was not consumed. Retry the scan.')->danger()->send();
        }
    }

    #[Computed]
    public function operationClosed(): bool
    {
        return $this->operationId !== null && $this->service()->operation($this->operationId, Auth::user())->closed_at !== null;
    }

    #[Computed]
    public function entries()
    {
        $service = $this->service();

        return $this->operationId ? $service->operation($this->operationId, Auth::user())->entries()
            ->whereNull('deleted_at')->with('product.asset')->orderByDesc('id')->get() : collect();
    }

    public function deleteProductAction(): Action
    {
        return Action::make('deleteProduct')->label('Delete')->color('danger')->requiresConfirmation()
            ->modalDescription('Delete this product only if it remains untouched in Stock, and release its serial?')
            ->action(function (array $arguments) {
                $service = $this->service();
                abort_unless($this->operationId !== null, 404);
                try {
                    $service->delete($this->operationId, (int) ($arguments['entry'] ?? 0), Auth::user());
                    unset($this->entries);
                    Notification::make()->title('Product deleted; serial is Free')->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title('Deletion blocked')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();
                } catch (QueryException $e) {
                    Log::error('Refill deletion blocked by database dependency', ['exception_type' => get_class($e)]);
                    Notification::make()->title('Deletion blocked')->body('A database dependency prevents deletion. Product and serial were preserved.')->danger()->send();
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newOperation')->label('New operation')->requiresConfirmation()->action(function () {
                $service = $this->service();
                $active = $service->operations(Auth::user())->where('active_user_id', Auth::id())->first();
                if ($active) {
                    $service->close($active->id, Auth::user());
                }
                $this->operationId = null;
                $this->serialInput = '';
                $this->form->fill($this->defaults());
                unset($this->entries, $this->operationClosed);
            }),
            Action::make('viewOperation')->label('View operation')->form([
                Select::make('operation')->required()->searchable()->options(fn () => $this->service()->operations(Auth::user())->get()
                    ->mapWithKeys(fn ($operation) => [$operation->id => '#'.$operation->id.' - '.$operation->created_at.' - '.($operation->closed_at ? 'Closed' : 'Open')])),
            ])->action(function (array $data) {
                $operation = $this->service()->operation((int) $data['operation'], Auth::user());
                $this->operationId = $operation->id;
                $this->serialInput = '';
                $this->form->fill($operation->data);
                unset($this->entries, $this->operationClosed);
            }),
        ];
    }

    private function defaults(): array
    {
        $catalog = app(ProductionCatalog::class);

        return ['assambled' => true, 'filled' => true, 'qualified' => true,
            'status_id' => $catalog->id('ready_product_status'), 'location_id' => $catalog->id('stock_location')];
    }

    private function service(): ExistingProductsRefillService
    {
        $service = app(ExistingProductsRefillService::class);
        $service->authorize(Auth::user());

        return $service;
    }
}
