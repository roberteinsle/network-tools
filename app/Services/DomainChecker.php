<?php

namespace App\Services;

use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Models\AppSetting;
use App\Models\CheckResult;
use App\Models\CheckRun;
use App\Models\Domain;
use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Führt alle aktiven Prüfungen für eine Domain aus, protokolliert das Ergebnis als
 * CheckRun und vergleicht es mit dem vorherigen Lauf. Bei Problemen oder Änderungen
 * geht eine E-Mail raus.
 */
class DomainChecker
{
    public function __construct(
        private readonly CheckRegistry $registry,
        private readonly AlertNotifier $notifier,
    ) {}

    public function run(Domain $domain, string $trigger = 'schedule'): CheckRun
    {
        $started = now();
        $t0 = hrtime(true);
        $outcomes = $this->evaluate($domain->loadMissing('customer'));

        $previous = $domain->latestRun()->with('results')->first();
        $overall = CheckStatus::worst(array_map(fn (CheckOutcome $o) => $o->status(), $outcomes));

        $run = DB::transaction(function () use ($domain, $outcomes, $overall, $trigger, $started, $t0) {
            $run = CheckRun::create([
                'domain_id' => $domain->id,
                'status' => $overall,
                'trigger' => $trigger,
                'started_at' => $started,
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $t0) / 1e6),
            ]);

            foreach ($outcomes as $check => $outcome) {
                CheckResult::create([
                    'check_run_id' => $run->id,
                    'domain_id' => $domain->id,
                    'check' => $check,
                    'status' => $outcome->status(),
                    'summary' => mb_strimwidth($outcome->getSummary(), 0, 500, '…'),
                    'details' => $outcome->details(),
                    'state' => $outcome->getState(),
                    'fingerprint' => $outcome->fingerprint(),
                ]);
            }

            $domain->forceFill([
                'last_status' => $overall,
                'last_checked_at' => now(),
            ])->saveQuietly();

            return $run;
        });

        $run->load('results');
        $changes = ChangeDetector::compare($previous, $run);
        $run->update(['changes' => $changes, 'changes_count' => count($changes)]);

        if ($this->shouldNotify($previous, $run, $changes, AppSetting::current())) {
            $mailChanges = array_values(array_filter($changes, fn ($c) => ! ($c['quiet'] ?? false)));
            if ($this->notifier->send($domain, $run, $mailChanges, $previous === null)) {
                $run->update(['notified_at' => now()]);
            }
        }

        return $run;
    }

    private function shouldNotify(?CheckRun $previous, CheckRun $run, array $changes, AppSetting $settings): bool
    {
        if ($previous === null) {
            // Erstprüfung: nur melden, wenn es Probleme gibt
            return $run->results->contains(fn (CheckResult $r) => $r->status->isProblem());
        }

        $changes = array_filter($changes, fn ($c) => ! ($c['quiet'] ?? false));
        if (! $settings->notify_on_recovery) {
            $changes = array_filter($changes, fn ($c) => $c['kind'] !== 'recovered');
        }

        return $changes !== [];
    }

    /**
     * Führt die registrierten Prüfungen aus, ohne etwas zu speichern oder zu melden
     * (auch für nicht gespeicherte Domains).
     *
     * @param  list<CheckType>|null  $only  nur diese Prüfungen (null = alle an der Domain aktiven)
     * @return array<string, CheckOutcome>
     */
    public function evaluate(Domain $domain, ?array $only = null): array
    {
        $settings = AppSetting::current();
        $ctx = new CheckContext(
            $domain,
            new DnsResolver($settings->dnsResolvers()),
            new DnsResolver($settings->rblResolvers(), 3.0),
            $settings,
        );
        $outcomes = [];

        foreach ($this->registry->all() as $check) {
            $type = $check->type();
            if ($only !== null ? ! in_array($type, $only, true) : ! $domain->isCheckEnabled($type)) {
                continue;
            }
            try {
                $outcomes[$type->value] = $check->run($ctx);
            } catch (DnsException $e) {
                $outcomes[$type->value] = CheckOutcome::make()->add(CheckStatus::Unknown, 'DNS nicht abfragbar: '.$e->getMessage());
            } catch (Throwable $e) {
                Log::warning("Check {$type->value} für {$domain->name} fehlgeschlagen", ['exception' => $e]);
                $outcomes[$type->value] = CheckOutcome::make()->add(CheckStatus::Unknown, 'Prüfung nicht möglich: '.mb_strimwidth($e->getMessage(), 0, 300, '…'));
            }
        }

        return $outcomes;
    }
}
