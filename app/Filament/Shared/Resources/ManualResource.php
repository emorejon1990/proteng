<?php

namespace App\Filament\Shared\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Manual;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Support\MediaEmbed;
use Illuminate\Support\Str;
use Filament\Resources\Resource;
use Mews\Purifier\Facades\Purifier;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Actions\Action;
use App\Filament\Shared\Resources\ManualResource\Pages;

class ManualResource extends Resource
{
    protected static ?string $model = Manual::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasRole(['Admin', 'Manager']) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasRole(['Admin', 'Manager']) ?? false;
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Manual')->schema([
                    Section::make()->schema([
                        Select::make('equipment_id')
                            ->label('Equipment')
                            ->relationship('equipment', 'brand')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->brand} - {$record->model}")
                            ->searchable()
                            ->live()
                            ->preload()
                            ->required(),
                    ])->columnSpan(2)->columns(2),

                    RichEditor::make('descript')
                        ->label('Description')
                        ->columnSpanFull()

                        // ✅ Upload local (como ya lo querías)
                        ->fileAttachmentsDirectory('manual')
                        ->fileAttachmentsVisibility('public')

                        // ✅ Botones extra para insertar ONLINE (sin descarga)
                        ->hintActions([
                            Action::make('insertImageUrl')
                                ->label('Insert image (URL)')
                                ->icon('heroicon-o-photo')
                                ->form([
                                    TextInput::make('url')
                                        ->label('Image URL')
                                        ->placeholder('https://.../image.jpg')
                                        ->required()
                                        ->url(),
                                    TextInput::make('alt')
                                        ->label('Alt (optional)')
                                        ->maxLength(150),
                                ])
                                ->action(function (array $data, $get, $set) {
                                    $url = trim($data['url']);
                                    $alt = e($data['alt'] ?? '');

                                    if (! Str::startsWith($url, ['http://', 'https://'])) {
                                        return;
                                    }

                                    $html = '<p><img src="' . e($url) . '" alt="' . $alt . '" style="max-width: 100%; height: auto;" /></p>';

                                    $current = $get('descript') ?? '';
                                    $set('descript', $current . $html);
                                }),

                            Action::make('insertVideoUrl')
                                ->label('Insert video (URL)')
                                ->icon('heroicon-o-video-camera')
                                ->form([
                                    TextInput::make('url')
                                        ->label('Video URL')
                                        ->placeholder('https://.../video.mp4  ó  https://.../player?id=123')
                                        ->required()
                                        ->url(),
                                ])
                                ->action(function (array $data, $get, $set) {
                                    $url = trim($data['url']);

                                    if (! Str::startsWith($url, ['http://', 'https://'])) {
                                        return;
                                    }

                                    // ✅ Normalizar YouTube:
                                    // 1) youtu.be/ID  -> youtube.com/embed/ID
                                    if (preg_match('~^https?://youtu\.be/([A-Za-z0-9_-]+)~', $url, $m)) {
                                        $url = "https://www.youtube.com/embed/{$m[1]}";
                                    }

                                    // 2) youtube.com/watch?v=ID -> youtube.com/embed/ID
                                    if (preg_match('~^https?://(www\.)?youtube\.com/watch\?v=([A-Za-z0-9_-]+)~', $url, $m)) {
                                        $url = "https://www.youtube.com/embed/{$m[2]}";
                                    }

                                    // ✅ Si ya viene como embed (youtube / vimeo / corporativo), lo usamos
                                    // (aquí puedes añadir validación por dominio si quieres)
                                    $iframe = '<iframe '
                                        . 'src="' . e($url) . '" '
                                        . 'frameborder="0" '
                                        . 'allowfullscreen="allowfullscreen" '
                                        . '></iframe>';

                                    $html = '<div class="embed-video my-4">'
                                        . $iframe
                                        . '</div>';

                                    $current = $get('descript') ?? '';
                                    $set('descript', $current . $html);
                                }),
                        ])
                        ->dehydrateStateUsing(function ($state) {
                            return Purifier::clean($state ?? '', 'youtube');
                        }),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('equipment_name')
                    ->label('Equipment')
                    ->searchable(),
            ])
            ->recordUrl(fn (Model $record) => static::getUrl('view', ['record' => $record])
            )
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListManuals::route('/'),
            'create' => Pages\CreateManual::route('/create'),
            'view'   => Pages\ViewManual::route('/{record}'),
            'edit'   => Pages\EditManual::route('/{record}/edit'),
        ];
    }
}
