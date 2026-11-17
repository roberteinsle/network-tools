<?php

namespace App\Filament\Resources\CheckRuns;

use App\Enums\CheckStatus;
use App\Filament\Resources\CheckRuns\Pages\ListCheckRuns;
use App\Filament\Resources\CheckRuns\Pages\ViewCheckRun;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\ResultSchema;
use App\Models\CheckRun;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CheckRunResource extends Resource
{
    protected static ?string $model = CheckRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Überwachung';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Prüflauf';

    protected static ?string $pluralModelLabel = 'Protokoll';

    protected static ?string $navigationLabel = 'Protokoll';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prüflauf')
                ->columns(['default' => 2, 'md' => 5])
                ->schema([
                    TextEntry::make('domain.name')->label('Domain')
                        ->url(fn (CheckRun $record) => DomainResource::getUrl('view', ['record' => $record->domain_id])),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('finished_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s'),
                    TextEntry::make('trigger')->label('Auslöser')
                        ->formatStateUsing(fn (string $state) => $state === 'manual' ? 'manuell' : 'automatisch'),
                    IconEntry::make('notified_at')->label('E-Mail gesendet')->boolean()
                        ->state(fn (CheckRun $record) => $record->notified_at !== null),
                ]),
            Section::make('Änderungen gegenüber dem vorherigen Lauf')
                ->schema([ResultSchema::changes()]),
            Section::make('Ergebnisse')
                ->headerActions(ResultSchema::copilotActions())
                ->schema([
                    ResultSchema::results('results'),
                    ResultSchema::copilot(
                        fn (?CheckRun $record) => $record?->domain?->name,
                        fn (?CheckRun $record) => $record?->results ?? [],
                        fn (?CheckRun $record) => $record?->finished_at?->format('d.m.Y H:i'),
                    ),
                ]),
        ])->columns(1);
    }

    /** @return list<Column> */
    public static function columns(bool $withDomain = true): array
    {
        return array_values(array_filter([
            TextColumn::make('finished_at')
                ->label('Zeitpunkt')
                ->dateTime('d.m.Y H:i')
                ->sortable(),
            $withDomain ? TextColumn::make('domain.name')
                ->label('Domain')
                ->searchable()
                ->sortable() : null,
            TextColumn::make('status')
                ->label('Status')
                ->badge(),
            TextColumn::make('changes_count')
                ->label('Änderungen')
                ->badge()
                ->color(fn (int $state) => $state > 0 ? 'warning' : 'gray')
                ->formatStateUsing(fn (int $state) => $state > 0 ? (string) $state : '–'),
            IconColumn::make('notified')
                ->label('E-Mail')
                ->state(fn (CheckRun $record) => $record->notified_at !== null)
                ->boolean()
                ->trueIcon(Heroicon::OutlinedEnvelope)
                ->falseIcon(Heroicon::Minus)
                ->falseColor('gray'),
            TextColumn::make('trigger')
                ->label('Auslöser')
                ->formatStateUsing(fn (string $state) => $state === 'manual' ? 'manuell' : 'automatisch')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('duration_ms')
                ->label('Dauer')
                ->formatStateUsing(fn ($state) => $state !== null ? number_format($state / 1000, 1, ',', '.').' s' : '–')
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('domain'))
            ->defaultSort('id', 'desc')
            ->columns(self::columns())
            ->filters([
                SelectFilter::make('domain')->label('Domain')->relationship('domain', 'name')->searchable()->preload(),
                SelectFilter::make('status')->label('Status')->options(CheckStatus::class),
                Filter::make('changes')->label('Nur mit Änderungen')->query(fn ($q) => $q->where('changes_count', '>', 0)),
                Filter::make('notified')->label('Nur mit E-Mail')->query(fn ($q) => $q->whereNotNull('notified_at')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckRuns::route('/'),
            'view' => ViewCheckRun::route('/{record}'),
        ];
    }
}
