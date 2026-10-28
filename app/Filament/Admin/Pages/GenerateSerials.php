<?php

namespace App\Filament\Admin\Pages;

use App\Services\SerialGenerationService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GenerateSerials extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Admin Tools';

    protected static string $view = 'filament.pages.generate-serials';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('Admin') && ! Auth::user()->hasRole('Customer');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['quantity' => 1]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('quantity')->label('Quantity')->required()->integer()->minValue(1)->maxValue(1000)])->statePath('data');
    }

    public function generate(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        try {
            $count = app(SerialGenerationService::class)->generate($data['quantity'], Auth::user())->count();
            Notification::make()->title($count.' serials generated')->success()->send();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Manual serial generation failed', ['exception_type' => get_class($e)]);
            Notification::make()->title('Serial generation failed')->body('No serials from this request were saved. Please retry.')->danger()->send();
        }
    }
}
