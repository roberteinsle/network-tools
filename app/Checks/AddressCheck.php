<?php

namespace App\Checks;

use App\Enums\CheckType;

class AddressCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Address;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $state = [];

        foreach ($ctx->domain->webHosts() as $host) {
            $a = $ctx->dns->a($host);
            $aaaa = $ctx->dns->aaaa($host);
            $cname = $ctx->dns->cname($host);
            $state[$host] = ['a' => $a, 'aaaa' => $aaaa];

            $out->data("{$host} A", $a);
            $out->data("{$host} AAAA", $aaaa);
            if ($cname) {
                $out->data("{$host} CNAME", $cname);
            }

            if ($a === [] && $aaaa === []) {
                $out->info("{$host}: keine A/AAAA-Records.");
            } else {
                $out->ok("{$host}: ".count($a).' × A, '.count($aaaa).' × AAAA');
                if ($aaaa === []) {
                    $out->info("{$host}: kein IPv6 (AAAA).");
                }
            }
        }

        foreach ($state as $host => $s) {
            foreach ([...$s['a'], ...$s['aaaa']] as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $out->warning("{$host} zeigt auf private/reservierte Adresse {$ip}.");
                }
            }
        }

        $hosts = array_filter($state, fn ($s) => $s['a'] !== [] || $s['aaaa'] !== []);
        if (! $out->status()->isProblem()) {
            $out->summary($hosts === []
                ? 'Keine Web-Adressen (A/AAAA) hinterlegt.'
                : implode(' · ', array_map(fn ($h, $s) => $h.': '.implode(', ', [...$s['a'], ...$s['aaaa']]), array_keys($hosts), $hosts)));
        }

        return $out->state($state);
    }
}
