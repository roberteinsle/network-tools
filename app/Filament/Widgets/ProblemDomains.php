<?php

namespace App\Filament\Widgets;

use App\Enums\CheckStatus;
use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ProblemDomains extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Domains mit Problemen';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Domain::query()
                ->active()
                ->whereIn('last_status', [CheckStatus::Error, CheckStatus::Warning])
                ->with(['customer', 'latestRun.results'])
                ->orderByRaw("case when last_status = 'error' then 0 else 1 end")
                ->orderBy('name'))
            ->paginated([10, 25])
            ->emptyStateHeading('Alles in Ordnung')
            ->emptyStateDescription('Keine aktive Domain hat Warnungen oder Fehler.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->recordUrl(fn (Domain $record) => DomainResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Domain')->weight('medium'),
                TextColumn::make('customer.name')->label('Kunde')->placeholder('–'),
                TextColumn::make('last_status')->label('Status')->badge(),
                TextColumn::make('problems')
                    ->label('Befunde')
                    ->state(fn (Domain $record) => $record->latestRun?->results
                        ->filter(fn ($r) => $r->status->isProblem())
                        ->sortByDesc(fn ($r) => $r->status->severity())
                        ->map(fn ($r) => $r->check->getLabel().': '.$r->summary)
                        ->values()
                        ->all() ?? [])
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Öffnen')
                    ->url(fn (Domain $record) => DomainResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
