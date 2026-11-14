<?php

namespace App\Filament\Widgets;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Filament\Resources\Domains\DomainResource;
use App\Models\CheckResult;
use App\Models\Domain;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $active = Domain::query()->active();
        $total = (clone $active)->count();
        $errors = (clone $active)->where('last_status', CheckStatus::Error)->count();
        $warnings = (clone $active)->where('last_status', CheckStatus::Warning)->count();
        $ok = (clone $active)->whereIn('last_status', [CheckStatus::Ok, CheckStatus::Info])->count();

        // Zertifikate, die bald ablaufen: TLS-Ergebnisse der jeweils letzten Läufe mit Warnung/Fehler
        $latestRunIds = Domain::query()->active()->with('latestRun')->get()->pluck('latestRun.id')->filter();
        $certs = CheckResult::query()
            ->whereIn('check_run_id', $latestRunIds)
            ->whereIn('check', [CheckType::TlsWeb, CheckType::TlsMx, CheckType::DomainExpiry])
            ->whereIn('status', [CheckStatus::Warning, CheckStatus::Error])
            ->count();

        return [
            Stat::make('Aktive Domains', $total)
                ->icon('heroicon-o-globe-alt')
                ->url(DomainResource::getUrl()),
            Stat::make('Fehler', $errors)
                ->color($errors ? 'danger' : 'gray')
                ->icon('heroicon-o-x-circle')
                ->url(DomainResource::getUrl('index', ['filters' => ['last_status' => ['value' => 'error']]])),
            Stat::make('Warnungen', $warnings)
                ->color($warnings ? 'warning' : 'gray')
                ->icon('heroicon-o-exclamation-triangle')
                ->url(DomainResource::getUrl('index', ['filters' => ['last_status' => ['value' => 'warning']]])),
            Stat::make('In Ordnung', $ok)
                ->color('success')
                ->icon('heroicon-o-check-circle'),
            Stat::make('Ablauf-Warnungen', $certs)
                ->description('Zertifikate und Domains')
                ->color($certs ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
