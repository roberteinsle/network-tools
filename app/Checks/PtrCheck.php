<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Dns\DnsException;

/**
 * Reverse DNS für die IPs der MX-Hosts und die zusätzlich gepflegten ausgehenden IPs,
 * inklusive Forward-Confirmed rDNS (PTR-Name löst wieder auf die IP auf).
 */
class PtrCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Ptr;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $targets = [];

        foreach ($ctx->mxIps() as $host => $ips) {
            foreach ($ips as $ip) {
                $targets[$ip] = "MX {$host}";
            }
        }
        foreach ($ctx->extraIps() as $ip) {
            $targets[$ip] ??= 'ausgehende IP';
        }

        if ($targets === []) {
            return $out->info('Keine IPs zu prüfen (kein MX, keine ausgehenden IPs gepflegt).')->state([]);
        }

        $state = [];
        foreach ($targets as $ip => $label) {
            try {
                $ptrs = $ctx->dns->ptr($ip);
            } catch (DnsException $e) {
                $out->info("{$ip} ({$label}): PTR nicht abfragbar – {$e->getMessage()}");

                continue;
            }

            $state[$ip] = $ptrs;
            $out->data("{$ip} ({$label})", $ptrs === [] ? 'kein PTR' : $ptrs);

            if ($ptrs === []) {
                // Für empfangende MX-Hosts weniger kritisch als für sendende IPs
                $label === 'ausgehende IP'
                    ? $out->error("{$ip}: kein PTR-Record – viele Empfänger lehnen Mails ab.")
                    : $out->warning("{$ip} ({$label}): kein PTR-Record.");

                continue;
            }

            if (count($ptrs) > 1) {
                $out->warning("{$ip}: mehrere PTR-Records (".implode(', ', $ptrs).').');
            }

            $confirmed = false;
            foreach ($ptrs as $ptr) {
                try {
                    $back = $ctx->dns->ips($ptr);
                } catch (DnsException) {
                    $back = [];
                }
                if (in_array(inet_pton($ip), array_map(fn ($b) => @inet_pton($b), $back), true)) {
                    $confirmed = true;
                }
            }

            $confirmed
                ? $out->ok("{$ip} → {$ptrs[0]} (FCrDNS bestätigt)")
                : $out->warning("{$ip} → {$ptrs[0]}, aber der Name löst nicht zurück auf die IP auf (kein FCrDNS).");
        }

        if (! $out->status()->isProblem()) {
            $out->summary(count($state).' IP(s) mit gültigem Reverse DNS');
        }

        return $out->state($state);
    }
}
