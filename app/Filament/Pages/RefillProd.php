<?php

namespace App\Filament\Pages;

// use Filament\Forms;

use App\Models\User;
use App\Models\Asset;
use App\Models\Status;
use App\Models\Location;
use App\Models\Products;
use Carbon\Carbon;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;

class RefillProd extends Page implements HasForms
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string $view = 'filament.pages.refill-prod';

    protected static ?string $navigationGroup = 'Admin Tools';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    use InteractsWithForms;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Products')->schema([
                    Select::make('asset_id')
                        ->label('Type')
                        ->options(Asset::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    // Campo nuevo: Cantidad de productos a crear
                    TextInput::make('quantity')
                        ->label('Cantidad')
                        ->numeric()
                        ->default(1)
                        ->required()
                        ->minValue(1),

                    Select::make('assambly_by')
                        ->label('Assambler')
                        ->options(User::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Checkbox::make('assambled'),

                    Select::make('fill_by')
                        ->label('Filler')
                        ->options(User::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Checkbox::make('filled'),

                    Select::make('quality_by')
                        ->label('Quality Checker')
                        ->options(User::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Checkbox::make('qualified'),

                    Select::make('status_id')
                        ->label('Status')
                        ->options(Status::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Select::make('location_id')
                        ->label('Location')
                        ->options(Location::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Select::make('weight')
                        ->label('Weight')
                        ->options(Asset::query()->pluck('weight', 'weight'))
                        ->searchable()
                        ->required(),
                ])->columnSpan(2)->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $this->data = $this->form->getState();
        // dd($this->data);
        $quantity = $this->data['quantity'] ?? 1;

        for ($i = 0; $i < $quantity; $i++) {
            $product = Products::create([
                'assambly_by'        => $this->data['assambly_by'],
                'assambly_date'        => Carbon::now(),
                'assambled'        => $this->data['assambled'],
                'fill_by'        => $this->data['fill_by'],
                'fill_date'        => Carbon::now(),
                'filled'        => $this->data['filled'],
                'weight'        => $this->data['weight'],
                'quality_by'        => $this->data['quality_by'],
                'quality_date'        => Carbon::now(),
                'qualified'        => $this->data['qualified'],
                'f_weight'        => $this->data['weight'],
                'status_id'        => $this->data['status_id'],
                'asset_id'        => $this->data['asset_id'],
                'location_id'        => $this->data['location_id'],
            ]);

            // Guardar relación muchos a muchos
            // if (!empty($this->data['suppliers'])) {
            //     $product->suppliers()->sync($this->data['suppliers']);
            // }
        }

        $this->reset('data');
        session()->flash('success', "Se crearon {$quantity} productos correctamente.");
    }

    public function mount(): void
    {
        $this->form->fill(); // 🔑 Esto inicializa el form con los valores (vacíos o por defecto)
    }

}
