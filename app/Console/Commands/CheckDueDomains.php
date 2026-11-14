<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainJob;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('domains:check-due')]
#[Description('Stellt alle fälligen Domain-Prüfungen in die Queue (läuft per Scheduler alle 5 Minuten).')]
class CheckDueDomains extends Command
{
    public function handle(): int
    {
        $count = 0;
        Domain::query()->due()->orderBy('next_check_at')->each(function (Domain $domain) use (&$count) {
            $domain->forceFill(['next_check_at' => now()->addMinutes(max(15, $domain->check_interval_minutes))])->saveQuietly();
            CheckDomainJob::dispatch($domain);
            $count++;
        });

        $this->info("{$count} Domain(s) zur Prüfung eingeplant.");

        return self::SUCCESS;
    }
}
