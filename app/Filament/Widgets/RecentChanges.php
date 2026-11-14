<?php

namespace App\Filament\Widgets;

use App\Enums\CheckType;
use App\Filament\Resources\CheckRuns\CheckRunResource;
use App\Models\CheckRun;
use App\Services\ChangeDetector;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentChanges extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Letzte Änderungen';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CheckRun::query()->where('changes_count', '>', 0)->with('domain')->latest('id'))
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Noch keine Änderungen erkannt')
            ->recordUrl(fn (CheckRun $record) => CheckRunResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('finished_at')->label('Zeitpunkt')->since()->dateTimeTooltip('d.m.Y H:i'),
                TextColumn::make('domain.name')->label('Domain')->weight('medium'),
                TextColumn::make('changes')
                    ->label('Änderungen')
                    ->state(fn (CheckRun $record) => collect($record->changes)
                        ->map(fn ($c) => (CheckType::tryFrom($c['check'])?->getLabel() ?? $c['check']).' – '.ChangeDetector::kindLabel($c['kind']))
                        ->all())
                    ->badge()
                    ->color(fn (string $state) => str_contains($state, 'Verschlechtert') ? 'danger' : (str_contains($state, 'Behoben') ? 'success' : 'warning'))
                    ->limitList(4),
            ]);
    }
}
