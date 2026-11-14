<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\DomainChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckDomainJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public Domain $domain, public string $trigger = 'schedule') {}

    public function uniqueId(): string
    {
        return (string) $this->domain->id;
    }

    public function handle(DomainChecker $checker): void
    {
        $checker->run($this->domain, $this->trigger);
    }
}
