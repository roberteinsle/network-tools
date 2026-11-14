<?php

namespace App\Filament\Resources\Blacklists;

use App\Filament\Resources\Blacklists\Pages\ManageBlacklists;
use App\Models\Blacklist;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class BlacklistResource extends Resource
{
    protected static ?string $model = Blacklist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Einstellungen';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Blacklist';

    protected static ?string $pluralModelLabel = 'Blacklists';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('zone')
                ->label('DNS-Zone')
                ->placeholder('bl.beispiel.org')
                ->required()
                ->maxLength(253)
                ->regex('/^[A-Za-z0-9.-]+$/')
                ->unique(ignoreRecord: true)
                // Spamhaus nur über DQS – die öffentlichen Zonen sind für kommerzielle Nutzung lizenzpflichtig
                ->rule(fn () => function (string $attribute, $value, $fail) {
                    if (preg_match('/spamhaus\.(org|net)$/i', (string) $value)) {
                        $fail('Spamhaus bitte nicht hier eintragen, sondern den DQS-Schlüssel in den Einstellungen hinterlegen.');
                    }
                }),
            Select::make('type')
                ->label('Typ')
                ->options(['ip' => 'IP-Liste (IPv4)', 'domain' => 'Domain-Liste'])
                ->default('ip')
                ->required()
                ->in(['ip', 'domain']),
            Toggle::make('enabled')->label('Aktiv')->default(true),
            TextInput::make('info_url')->label('Info-URL')->url()->maxLength(255),
            Textarea::make('description')->label('Beschreibung / Lizenzhinweis')->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Nur kostenlose RBLs mit geringem Abfragevolumen. Spamhaus ausschließlich über DQS (Einstellungen).')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->weight('medium')
                    ->url(fn (Blacklist $record) => $record->info_url, shouldOpenInNewTab: true),
                TextColumn::make('zone')->label('Zone')->fontFamily('mono')->copyable(),
                TextColumn::make('type')->label('Typ')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => $state === 'ip' ? 'IP' : 'Domain'),
                TextColumn::make('description')->label('Hinweis')->limit(60)->tooltip(fn (Blacklist $record) => $record->description)->toggleable(),
                ToggleColumn::make('enabled')->label('Aktiv'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBlacklists::route('/'),
        ];
    }
}
