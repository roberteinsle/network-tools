<?php

namespace App\Filament\Support;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Services\ChangeDetector;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Collection;

/** Wiederverwendbare Infolist-Bausteine für Prüfergebnisse und Änderungen. */
class ResultSchema
{
    /**
     * Ergebnisse sortiert nach Schwere (Fehler, Warnung, nicht prüfbar, Hinweis, OK), innerhalb
     * gleicher Schwere in Prüf-Reihenfolge. Details sind bei allem außer OK aufgeklappt.
     */
    public static function results(string $path, string $label = 'Ergebnisse'): RepeatableEntry
    {
        return RepeatableEntry::make($path)
            ->label($label)
            ->hiddenLabel()
            ->state(fn ($record) => self::bySeverity(data_get($record, $path), fn ($r) => $r->status))
            ->placeholder('Noch keine Prüfung durchgeführt.')
            ->schema([
                Grid::make(['default' => 1, 'md' => 6])->schema([
                    TextEntry::make('check')
                        ->hiddenLabel()
                        ->weight('bold')
                        ->columnSpan(['md' => 2]),
                    TextEntry::make('status')
                        ->hiddenLabel()
                        ->badge()
                        ->columnSpan(['md' => 1]),
                    TextEntry::make('summary')
                        ->hiddenLabel()
                        ->columnSpan(['md' => 3]),
                ]),
                Section::make('Details')
                    ->collapsed(fn ($record) => $record?->status === CheckStatus::Ok)
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('details.messages')
                            ->hiddenLabel()
                            ->state(fn ($record) => self::bySeverity($record?->details['messages'] ?? [], fn ($m) => CheckStatus::tryFrom($m['status']) ?? CheckStatus::Unknown)->all())
                            ->contained(false)
                            ->schema([
                                Grid::make(['default' => 1, 'md' => 6])->schema([
                                    TextEntry::make('status')
                                        ->hiddenLabel()
                                        ->badge()
                                        ->formatStateUsing(fn (string $state) => CheckStatus::from($state)->getLabel())
                                        ->color(fn (string $state) => CheckStatus::from($state)->getColor())
                                        ->icon(fn (string $state) => CheckStatus::from($state)->getIcon()),
                                    TextEntry::make('text')->hiddenLabel()->columnSpan(['md' => 5]),
                                ]),
                            ]),
                        RepeatableEntry::make('data_rows')
                            ->hiddenLabel()
                            ->contained(false)
                            ->state(fn ($record) => collect($record?->details['data'] ?? [])
                                ->map(fn ($v, $k) => ['key' => $k, 'value' => explode("\n", (string) $v)])
                                ->values()
                                ->all())
                            ->schema([
                                Grid::make(['default' => 1, 'md' => 6])->schema([
                                    TextEntry::make('key')->hiddenLabel()->weight('medium')->color('gray'),
                                    TextEntry::make('value')->hiddenLabel()->listWithLineBreaks()->fontFamily('mono')->size('sm')->columnSpan(['md' => 5]),
                                ]),
                            ]),
                    ]),
            ]);
    }

    /** Stabile Sortierung nach Schweregrad, schwerster zuerst. */
    private static function bySeverity(?iterable $items, callable $status): Collection
    {
        return collect($items ?? [])
            ->values()
            ->map(fn ($item, $i) => [$item, $i])
            ->sort(fn ($a, $b) => [$status($b[0])->severity(), $a[1]] <=> [$status($a[0])->severity(), $b[1]])
            ->map(fn ($pair) => $pair[0])
            ->values();
    }

    public static function changes(string $path = 'changes'): RepeatableEntry
    {
        return RepeatableEntry::make($path)
            ->hiddenLabel()
            ->placeholder('Keine Änderungen gegenüber dem vorherigen Lauf.')
            ->schema([
                Grid::make(['default' => 1, 'md' => 6])->schema([
                    TextEntry::make('check')
                        ->hiddenLabel()
                        ->weight('bold')
                        ->formatStateUsing(fn (string $state) => CheckType::tryFrom($state)?->getLabel() ?? $state),
                    TextEntry::make('kind')
                        ->hiddenLabel()
                        ->badge()
                        ->formatStateUsing(fn (string $state) => ChangeDetector::kindLabel($state))
                        ->color(fn (string $state) => match ($state) {
                            'worse' => 'danger',
                            'recovered' => 'success',
                            default => 'warning',
                        }),
                    TextEntry::make('from_summary')
                        ->label('Vorher')
                        ->placeholder('–')
                        ->columnSpan(['md' => 2]),
                    TextEntry::make('to_summary')
                        ->label('Jetzt')
                        ->columnSpan(['md' => 2]),
                ]),
            ]);
    }
}
