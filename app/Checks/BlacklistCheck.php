<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Models\Blacklist;
use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;

/**
 * Blacklist-Abfrage der MX-IPs, ausgehenden IPs und der Domain selbst.
 * Nur kostenlose RBLs aus der Tabelle „blacklists“; Spamhaus ausschließlich über DQS
 * (eigener Schlüssel in den Einstellungen) – die öffentlichen Spamhaus-Zonen werden nie abgefragt.
 */
class BlacklistCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Blacklist;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $lists = Blacklist::query()->where('enabled', true)->orderBy('name')->get()
            ->map(fn (Blacklist $b) => ['name' => $b->name, 'zone' => $b->zone, 'type' => $b->type, 'spamhaus' => false])
            ->all();

        if ($key = trim((string) $ctx->settings->spamhaus_dqs_key)) {
            $lists[] = ['name' => 'Spamhaus ZEN (DQS)', 'zone' => "{$key}.zen.dq.spamhaus.net", 'type' => 'ip', 'spamhaus' => true];
            $lists[] = ['name' => 'Spamhaus DBL (DQS)', 'zone' => "{$key}.dbl.dq.spamhaus.net", 'type' => 'domain', 'spamhaus' => true];
        }

        if ($lists === []) {
            return $out->info('Keine Blacklists aktiviert.')->state([]);
        }

        // Nur IPv4 – die gängigen kostenlosen RBLs führen keine IPv6-Listen
        $ips = [];
        foreach ($ctx->mxIps() as $host => $hostIps) {
            foreach ($hostIps as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[$ip] = "MX {$host}";
                }
            }
        }
        foreach ($ctx->extraIps() as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[$ip] = 'ausgehende IP';
            }
        }

        $listed = [];
        $queries = 0;
        $refused = [];

        foreach ($lists as $list) {
            $targets = $list['type'] === 'domain'
                ? [$ctx->name() => 'Domain']
                : $ips;

            foreach ($targets as $target => $label) {
                $queries++;
                $query = ($list['type'] === 'domain' ? $target : implode('.', array_reverse(explode('.', $target)))).'.'.$list['zone'];
                $result = $this->lookup($ctx->rblDns, $query, $list);

                if ($result === 'refused') {
                    $refused[$list['name']] = true;
                } elseif ($result !== null) {
                    $listed[] = "{$list['name']}: {$target}";
                    $out->error("{$target} ({$label}) ist gelistet bei {$list['name']} ({$result}).");
                }
            }
        }

        foreach (array_keys($refused) as $name) {
            $out->add(CheckStatus::Unknown, "{$name}: Abfrage abgelehnt oder nicht erreichbar – Resolver ggf. gesperrt (eigenen Resolver in den Einstellungen setzen).");
        }

        $out->data('Geprüfte IPs', array_map(fn ($ip, $l) => "{$ip} ({$l})", array_keys($ips), $ips))
            ->data('Listen', array_map(fn ($l) => $l['spamhaus'] ? $l['name'] : "{$l['name']} ({$l['zone']})", $lists))
            ->data('Listungen', $listed === [] ? 'keine' : $listed);

        if ($listed === []) {
            $clean = 'Auf keiner von '.count($lists).' Listen gefunden ('.count($ips).' IP(s) + Domain).';
            $out->ok($clean);
            if ($refused === []) {
                $out->summary($clean);
            }
        }

        sort($listed);

        return $out->state(['listed' => $listed]);
    }

    /** @return string|null|'refused' Rückgabecode bei Listung, null wenn nicht gelistet */
    private function lookup(DnsResolver $dns, string $query, array $list): ?string
    {
        try {
            $codes = $dns->a($query);
        } catch (DnsException) {
            return 'refused';
        }

        if ($codes === []) {
            return null;
        }

        foreach ($codes as $code) {
            // Spamhaus-Fehlercodes 127.255.255.x (z. B. ungültiger DQS-Schlüssel)
            if (str_starts_with($code, '127.255.255.')) {
                return 'refused';
            }
            // URIBL/SURBL: 127.0.0.1 = Abfrage blockiert (öffentlicher Resolver / Limit)
            if ($list['type'] === 'domain' && $code === '127.0.0.1') {
                return 'refused';
            }
            if (! str_starts_with($code, '127.')) {
                return 'refused'; // Wildcard/Fehlkonfiguration der Liste
            }
        }

        return implode(', ', $codes);
    }
}
