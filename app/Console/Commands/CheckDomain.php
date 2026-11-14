<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\DomainChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('domains:check {domain? : Domainname, ohne Angabe alle aktiven}')]
#[Description('Prüft Domains sofort (synchron) und gibt das Ergebnis aus.')]
class CheckDomain extends Command
{
    public function handle(DomainChecker $checker): int
    {
        $query = Domain::query()->active();
        if ($name = $this->argument('domain')) {
            $query = Domain::query()->where('name', Domain::normalizeName($name));
        }

        $domains = $query->get();
        if ($domains->isEmpty()) {
            $this->error('Keine passende Domain gefunden.');

            return self::FAILURE;
        }

        foreach ($domains as $domain) {
            $run = $checker->run($domain, 'manual');
            $this->newLine();
            $this->line("<options=bold>{$domain->name}</> – {$run->status->getLabel()} ({$run->duration_ms} ms)");
            $this->table(['Prüfung', 'Status', 'Ergebnis'], $run->results->map(fn ($r) => [
                $r->check->getLabel(), $r->status->getLabel(), mb_strimwidth($r->summary, 0, 110, '…'),
            ]));
            if ($run->changes_count) {
                $this->warn("{$run->changes_count} Änderung(en) gegenüber dem letzten Lauf.");
            }
        }

        return self::SUCCESS;
    }
}
