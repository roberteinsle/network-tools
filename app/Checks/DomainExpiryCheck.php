<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Rdap\RdapClient;
use Carbon\CarbonImmutable;

class DomainExpiryCheck implements Check
{
    private const BAD_STATUS = ['client hold', 'server hold', 'redemption period', 'pending delete', 'inactive'];

    public function __construct(private readonly RdapClient $rdap) {}

    public function type(): CheckType
    {
        return CheckType::DomainExpiry;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $info = $this->rdap->lookup($ctx->name());

        if ($info === null) {
            return $out->info('Für diese TLD gibt es keinen RDAP-Dienst – Ablaufdatum nicht abfragbar (z. B. .de/DENIC). Bitte beim Registrar prüfen.')
                ->state(['rdap' => false]);
        }

        $status = array_map('strtolower', $info['status']);
        $out->data('Registrar', $info['registrar'] ?? '–')
            ->data('Registriert seit', $info['registration'] ? CarbonImmutable::parse($info['registration'])->format('d.m.Y') : '–')
            ->data('Status', $info['status'])
            ->data('RDAP-Server', $info['server']);

        foreach (self::BAD_STATUS as $bad) {
            if (in_array($bad, $status, true)) {
                $out->error("Domain-Status „{$bad}“ – die Domain ist nicht (mehr) aktiv.");
            }
        }

        if ($info['expiration'] === null) {
            $out->info('Registry veröffentlicht kein Ablaufdatum.');

            return $out->state(['rdap' => true, 'expiration' => null, 'status' => $status]);
        }

        $expires = CarbonImmutable::parse($info['expiration']);
        $days = (int) floor(now()->diffInDays($expires, false));
        $s = $ctx->settings;
        $out->data('Läuft ab', $expires->format('d.m.Y')." ({$days} Tage)");

        if ($days < 0) {
            $out->error('Domain ist seit '.abs($days).' Tagen abgelaufen.');
        } elseif ($days <= $s->domain_error_days) {
            $out->error("Domain läuft in {$days} Tagen ab ({$expires->format('d.m.Y')}).");
        } elseif ($days <= $s->domain_warning_days) {
            $out->warning("Domain läuft in {$days} Tagen ab ({$expires->format('d.m.Y')}).");
        } else {
            $out->ok("Registriert bis {$expires->format('d.m.Y')} ({$days} Tage).");
        }

        if (! in_array('client transfer prohibited', $status, true) && ! in_array('server transfer prohibited', $status, true)) {
            $out->info('Keine Transfersperre (clientTransferProhibited) gesetzt.');
        }

        return $out->state(['rdap' => true, 'expiration' => $expires->toDateString(), 'status' => $status]);
    }
}
