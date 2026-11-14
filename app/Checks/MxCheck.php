<?php

namespace App\Checks;

use App\Enums\CheckType;

class MxCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Mx;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $mx = $ctx->mx();

        if ($mx === []) {
            $hasA = $ctx->dns->ips($ctx->name()) !== [];

            return $out->warning($hasA
                ? 'Kein MX-Record. Mails werden an den A/AAAA-Record zugestellt (Implicit MX).'
                : 'Kein MX-Record und keine Adresse – die Domain kann keine Mails empfangen.')
                ->state(['mx' => []]);
        }

        if ($ctx->hasNullMx()) {
            return $out->info('Null-MX (RFC 7505): Die Domain empfängt bewusst keine E-Mails.')
                ->data('MX', '0 .')
                ->state(['mx' => ['0 .']]);
        }

        $lines = [];
        foreach ($mx as $entry) {
            $host = $entry['host'];
            $lines[] = "{$entry['pref']} {$host}";

            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $out->error("MX {$host} ist eine IP-Adresse statt eines Hostnamens (RFC 5321).");

                continue;
            }

            $ips = $ctx->mxIps()[$host] ?? [];
            if ($ips === []) {
                $out->error("MX {$host} hat keinen A/AAAA-Record.");
            } else {
                $out->ok("{$host} → ".implode(', ', $ips));
            }

            if ($ctx->dns->cname($host) !== null) {
                $out->warning("MX {$host} zeigt auf einen CNAME (RFC 2181 verbietet das).");
            }
        }

        if (count($mx) === 1) {
            $out->info('Nur ein MX-Host – kein Ausweich-Mailserver (bei gehosteten Diensten wie M365 normal).');
        }

        $problems = $out->status()->isProblem();

        return $out
            ->data('MX-Records', $lines)
            ->summary($problems ? $out->getSummary() : count($mx).' MX-Record(s): '.implode(', ', array_column($mx, 'host')))
            ->state(['mx' => $lines]);
    }
}
