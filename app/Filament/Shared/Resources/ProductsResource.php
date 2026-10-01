<?php

namespace App\Filament\Shared\Resources;

use Filament\Tables;
use App\Models\Products;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Database\Eloquent\Collection;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode39;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Checkbox;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use App\Filament\Shared\Resources\ProductsResource\Pages;

class ProductsResource extends Resource
{
    protected static ?string $model = Products::class;
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole(['Admin', 'Manager']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('serial')
                    ->required(),

                Select::make('assambly_by')
                    ->label('Assambler')
                    ->relationship('assambler', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Checkbox::make('assambled'),

                Select::make('fill_by')
                    ->label('Filler')
                    ->relationship('filler', 'name')
                    ->searchable()
                    ->preload(),

                Checkbox::make('filled'),

                Select::make('quality_by')
                    ->label('Quality Checker')
                    ->relationship('qualityChecker', 'name')
                    ->searchable()
                    ->preload(),

                Checkbox::make('qualifiled'),

                Select::make('asset_id')
                    ->label('Product')
                    ->relationship('asset', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('status_id')
                    ->label('Status')
                    ->relationship('status', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('location_id')
                    ->label('Location')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('weight')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([100])
            ->columns([
                TextColumn::make('asset_name')->label('Product'),
                TextColumn::make('serial'),
                TextColumn::make('weight')->suffix('g'),
                TextColumn::make('assambler_name')->label('Assambler'),
                TextColumn::make('filler_name')->label('Filler'),
                TextColumn::make('quality_checker_name')->label('Quality Checker'),
                TextColumn::make('status_name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'Waiting' => 'info',
                        'Ready' => 'success',
                        default => 'secondary',
                    }),
                TextColumn::make('location_name')->label('Location'),
            ])
            ->filters([
                // Agregá filtros si querés
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('exportSerialsPdf')
                        ->label('Export serials to PDF')
                        ->icon('heroicon-o-document-arrow-down')
                        ->action(function (Collection $records) {
                            $products = $records->sortBy('serial')->values();
                            $invalidSerial = $products->first(
                                fn (Products $product) => preg_match(
                                    '/^[0-9A-Z\-. $\/+%]+$/',
                                    (string) $product->serial
                                ) !== 1
                            );

                            if ($invalidSerial) {
                                Notification::make()
                                    ->title('Invalid Code 39 serial')
                                    ->body("Serial {$invalidSerial->serial} contains unsupported characters.")
                                    ->danger()
                                    ->send();

                                return null;
                            }

                            $type = new TypeCode39();
                            $renderer = (new SvgRenderer())
                                ->setSvgType(SvgRenderer::TYPE_SVG_INLINE)
                                ->setBackgroundColor([255, 255, 255]);

                            $barcodes = $products->mapWithKeys(function (Products $product) use ($type, $renderer): array {
                                $barcode = $type->getBarcode((string) $product->serial);
                                $svg = $renderer->render($barcode, 140, 28);

                                return [
                                    $product->getKey() => 'data:image/svg+xml;base64,' . base64_encode($svg),
                                ];
                            });

                            $pdf = Pdf::loadView(
                                'pdf.product-serials',
                                compact('products', 'barcodes')
                            )->setPaper('letter', 'portrait');

                            return response()->streamDownload(
                                fn () => print($pdf->output()),
                                'product-serials-' . now()->format('Y-m-d-His') . '.pdf'
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('previewSerialsHtml')
                        ->label('Preview serials')
                        ->icon('heroicon-o-eye')
                        ->action(function (Collection $records) {
                            return redirect()->to(
                                URL::temporarySignedRoute(
                                    'products.serials.preview',
                                    now()->addMinutes(15),
                                    ['products' => $records->modelKeys()]
                                )
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Añadí RelationManagers si usás relaciones hasMany o morphMany
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProducts::route('/create'),
            'edit' => Pages\EditProducts::route('/{record}/edit'),
        ];
    }
}
