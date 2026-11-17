<?php

namespace App\Filament\Resources\AiModels;

use App\Enums\AiProvider;
use App\Filament\Resources\AiModels\Pages\ManageAiModels;
use App\Models\AiModel;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProviderRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** KI-Modelle für den Copilot: Anbieter, Modell-ID und API-Schlüssel (verschlüsselt gespeichert). */
class AiModelResource extends Resource
{
    protected static ?string $model = AiModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Einstellungen';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'KI-Modell';

    protected static ?string $pluralModelLabel = 'KI-Modelle';

    protected static ?string $slug = 'ai-models';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $provider = fn (Get $get) => ($v = $get('provider')) instanceof AiProvider ? $v : AiProvider::tryFrom((string) $v);

        return $schema->components([
            TextInput::make('name')->label('Name')->required()->maxLength(100)->placeholder('z. B. Claude Opus'),
            Select::make('provider')->label('Anbieter')
                ->options(AiProvider::class)
                ->required()
                ->live()
                ->helperText(fn (Get $get) => $provider($get)?->getDescription()),
            TextInput::make('model')->label('Modell-ID')
                ->required()
                ->maxLength(150)
                ->placeholder(fn (Get $get) => $provider($get)?->modelPlaceholder() ?? 'Modell-ID')
                ->datalist(fn (Get $get) => $provider($get)?->suggestedModels() ?? [])
                ->helperText('Genau so, wie der Anbieter das Modell in seiner API nennt.'),
            TextInput::make('api_key')->label('API-Schlüssel')
                ->password()
                ->revealable()
                ->maxLength(500)
                ->autocomplete('new-password')
                ->required(fn (Get $get, ?AiModel $record) => $record === null && ($provider($get)?->requiresApiKey() ?? true))
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (?AiModel $record) => $record && filled($record->api_key)
                    ? 'Gespeichert (verschlüsselt). Leer lassen, um ihn zu behalten.'
                    : 'Wird mit APP_KEY verschlüsselt gespeichert.'),
            TextInput::make('base_url')->label('Basis-URL')
                ->url()
                ->maxLength(255)
                ->visible(fn (Get $get) => $provider($get) !== null)
                ->required(fn (Get $get) => $provider($get)?->requiresBaseUrl() ?? false)
                ->placeholder(fn (Get $get) => $provider($get)?->defaultBaseUrl() ?? 'https://mein-server:11434/v1')
                ->helperText('Nur nötig für eigene Server oder Proxys; leer = Standard des Anbieters.'),
            Toggle::make('is_default')->label('Standardmodell für den Copilot'),
            Toggle::make('active')->label('Aktiv')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Modelle für den KI-Copilot an den Prüfergebnissen. Beim Abruf werden die Ergebnisse der Domain an den Anbieter übertragen.')
            ->defaultSort('name')
            ->emptyStateHeading('Noch kein KI-Modell')
            ->emptyStateDescription('Lege ein Modell an, z. B. Claude von Anthropic, ChatGPT von OpenAI, Gemini oder ein Modell über OpenRouter.')
            ->columns([
                TextColumn::make('name')->label('Name')->weight('medium')->searchable(),
                TextColumn::make('provider')->label('Anbieter')->badge()->color('gray')
                    ->formatStateUsing(fn (AiProvider $state) => $state->shortLabel()),
                TextColumn::make('model')->label('Modell')->fontFamily('mono'),
                IconColumn::make('is_default')->label('Standard')
                    ->icon(fn (bool $state) => $state ? Heroicon::Star : null)
                    ->color('warning'),
                ToggleColumn::make('active')->label('Aktiv'),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Testen')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->action(function (AiModel $record, AiProviderRegistry $providers) {
                        try {
                            $answer = $providers->complete($record, 'Antworte knapp.', 'Antworte nur mit dem Wort OK.');
                            Notification::make()->title('Verbindung funktioniert')->body('Antwort: '.mb_strimwidth($answer, 0, 120, '…'))->success()->send();
                        } catch (AiException $e) {
                            Notification::make()->title('Test fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiModels::route('/'),
        ];
    }
}
