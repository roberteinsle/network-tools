<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Support\Dns\DnsException;
use App\Support\Dns\NameserverProbe;

/**
 * Nameserver und SOA: Delegation und Glue beim Parent (Registry), Anzahl und Adressen der NS,
 * autoritative Antworten (Lame Delegation), Antwortzeiten, gleiche SOA-Serial und gleiche
 * NS-Liste auf allen Servern, Netz-Verteilung, SOA-Werte.
 */
class NsCheck implements Check
{
    public function __construct(private readonly NameserverProbe $probe) {}

    public function type(): CheckType
    {
        return CheckType::Ns;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $name = $ctx->name();
        $ns = $ctx->dns->ns($name);

        if ($ns === []) {
            return $out->error('Keine NS-Records gefunden – die Domain ist nicht delegiert.')->state(['ns' => []]);
        }
        if (count($ns) === 1) {
            $out->warning('Nur ein Nameserver – fällt er aus, ist die Domain nicht erreichbar (RFC 2182: mindestens zwei).');
        }

        $soas = [];
        $ips = [];
        $lame = [];
        $unreachable = [];
        $nsSets = [];
        $times = [];

        foreach ($ns as $host) {
            try {
                $hostIps = $ctx->dns->ips($host);
            } catch (DnsException) {
                $hostIps = [];
            }
            if ($hostIps === []) {
                $out->error("{$host}: keine A/AAAA-Adresse – Nameserver nicht erreichbar.");
                $lame[] = $host;

                continue;
            }
            $ips[$host] = $hostIps;

            // IPv4 bevorzugen: viele Server haben kein IPv6 und würden sonst Fehlalarme erzeugen
            $v4 = array_values(array_filter($hostIps, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
            $ip = $v4[0] ?? $hostIps[0];
            $resolver = $this->probe->resolver($ip);

            try {
                $t0 = hrtime(true);
                $answer = $resolver->query($name, 'SOA');
                $times[$host] = (int) round((hrtime(true) - $t0) / 1e6);
            } catch (DnsException $e) {
                if ($e->isRefused() || $e->isServFail()) {
                    $out->error("{$host} ({$ip}): verweigert die Antwort für die Zone (Lame Delegation).");
                    $lame[] = $host;
                } else {
                    $unreachable[] = $host;
                    $out->add(CheckStatus::Unknown, "{$host} ({$ip}): nicht erreichbar – {$e->getMessage()}");
                }

                continue;
            }

            $soa = $answer->ofType('SOA')[0] ?? null;
            if ($soa === null || ! $answer->authoritative) {
                $out->error("{$host} ({$ip}): antwortet nicht autoritativ für die Zone (Lame Delegation).");
                $lame[] = $host;

                continue;
            }
            $soas[$host] = $soa;

            try {
                $nsSets[$host] = $resolver->ns($name);
            } catch (DnsException) {
                // NS-Liste nicht abfragbar: kein eigener Befund, SOA hat geantwortet
            }
        }

        // Ein Teil der Server nicht erreichbar, andere schon: echtes Problem, kein Netzfehler auf unserer Seite
        if ($unreachable !== [] && $soas !== []) {
            foreach ($unreachable as $host) {
                $out->warning("{$host}: antwortet nicht, die übrigen Nameserver schon.");
            }
        }

        $out->data('Nameserver', array_map(fn ($h) => $h.' → '.implode(', ', $ips[$h] ?? ['keine Adresse']), $ns));
        // Antwortzeiten nur anzeigen: sie schwanken und dürfen weder Status noch Zustand beeinflussen
        if ($times !== []) {
            $out->data('Antwortzeit', array_map(fn ($h, $ms) => "{$h}: {$ms} ms", array_keys($times), $times));
        }

        $delegation = $this->delegation($ctx, $out, $name, $ns, $ips);

        if ($soas === []) {
            return $out->state(['ns' => $ns, 'lame' => $lame, 'delegation' => $delegation]);
        }

        $serials = array_map(fn ($rr) => (int) $rr->serial, $soas);
        $out->data('SOA-Serial', array_map(fn ($h, $s) => "{$h}: {$s}", array_keys($serials), $serials));
        $serialMismatch = count(array_unique($serials)) > 1;
        if ($serialMismatch) {
            $primaries = array_unique(array_map(fn ($rr) => strtolower(rtrim((string) $rr->mname, '.')), $soas));
            // Mehrere DNS-Anbieter (eigener Primary je Anbieter) haben zwangsläufig eigene Serials
            count($primaries) > 1
                ? $out->info('SOA-Serial unterschiedlich – mehrere DNS-Anbieter mit eigenem Primary ('.implode(', ', $primaries).'); Inhalte bitte synchron halten.')
                : $out->warning('SOA-Serial unterschiedlich ('.implode(', ', array_unique($serials)).') – die Nameserver liefern verschiedene Zonenstände.');
        }

        $nsMismatch = [];
        foreach ($nsSets as $host => $set) {
            if ($set !== [] && $set !== $ns) {
                $nsMismatch[] = $host;
                $out->warning("{$host}: NS-Liste in der Zone (".implode(', ', $set).') weicht von der Delegation ab.');
            }
        }

        $v4Nets = [];
        foreach ($ips as $hostIps) {
            foreach ($hostIps as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $v4Nets[] = implode('.', array_slice(explode('.', $ip), 0, 3));
                }
            }
        }
        if (count($ips) > 1 && count(array_unique($v4Nets)) === 1) {
            $out->info("Alle Nameserver liegen im selben Netz ({$v4Nets[0]}.0/24) – ein Netzausfall trifft alle.");
        }

        $soa = reset($soas);
        $refresh = (int) $soa->refresh;
        $retry = (int) $soa->retry;
        $expire = (int) $soa->expire;
        $minimum = (int) $soa->minimum;
        $out->data('SOA', [
            'Primary: '.rtrim((string) $soa->mname, '.'),
            'Kontakt: '.rtrim((string) $soa->rname, '.'),
            "Refresh {$refresh} s, Retry {$retry} s, Expire {$expire} s, Minimum {$minimum} s",
        ]);

        if ($retry >= $refresh) {
            $out->warning("SOA: retry ({$retry} s) sollte kleiner als refresh ({$refresh} s) sein.");
        }
        if ($expire <= $refresh + $retry) {
            $out->warning("SOA: expire ({$expire} s) ist kleiner als refresh + retry – Secondaries verwerfen die Zone zu früh.");
        } elseif ($expire < 604800) {
            $out->info("SOA: expire ({$expire} s) unter einer Woche – empfohlen sind 2 bis 4 Wochen.");
        }
        if ($minimum > 86400) {
            $out->info("SOA: Negativ-TTL (minimum {$minimum} s) über einem Tag – neue Records sind lange unsichtbar.");
        }

        if (! $out->status()->isProblem() && $unreachable === []) {
            $out->ok(count($ns).' Nameserver, alle autoritativ'.($serialMismatch ? '.' : ', Serial '.reset($serials).'.'));
            $out->summary(count($ns).' Nameserver autoritativ'.($serialMismatch ? ', mehrere DNS-Anbieter' : ', SOA-Serial einheitlich'));
        }

        // Serial absichtlich nicht im Zustand: sie ändert sich bei jeder Zonenänderung
        return $out->state([
            'ns' => $ns,
            'ips' => $ips,
            'lame' => $lame,
            'serial_mismatch' => $serialMismatch,
            'ns_mismatch' => $nsMismatch,
            'delegation' => $delegation,
            'soa' => [rtrim((string) $soa->mname, '.'), rtrim((string) $soa->rname, '.'), $refresh, $retry, $expire, $minimum],
        ]);
    }

    /**
     * Fragt einen Server der übergeordneten Zone (bei example.com die .com-Server) direkt nach
     * der Delegation: welche NS trägt die Registry ein, und gibt es Glue für NS in der eigenen Domain?
     *
     * @param  list<string>  $ns  NS-Records laut Resolver
     * @param  array<string, list<string>>  $ips  aufgelöste Adressen der NS
     */
    private function delegation(CheckContext $ctx, CheckOutcome $out, string $name, array $ns, array $ips): ?array
    {
        $labels = explode('.', $name);
        $parent = null;
        $parentNs = [];
        for ($i = 1; $i < count($labels) && $parentNs === []; $i++) {
            $parent = implode('.', array_slice($labels, $i));
            try {
                $parentNs = $ctx->dns->ns($parent);
            } catch (DnsException) {
                $parentNs = [];
            }
        }
        if ($parentNs === []) {
            return null;
        }

        $answer = null;
        $server = null;
        foreach (array_slice($parentNs, 0, 3) as $candidate) {
            try {
                $candidateIps = $ctx->dns->ips($candidate);
                $v4 = array_values(array_filter($candidateIps, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
                if (($ip = $v4[0] ?? $candidateIps[0] ?? null) === null) {
                    continue;
                }
                $answer = $this->probe->resolver($ip)->query($name, 'NS');
                $server = $candidate;
                break;
            } catch (DnsException) {
                continue;
            }
        }

        if ($answer === null) {
            $out->add(CheckStatus::Unknown, "Delegation bei {$parent} nicht abfragbar – kein Server der übergeordneten Zone antwortet.");

            return null;
        }
        if ($answer->nxdomain) {
            $out->error("Die Registry ({$parent}) kennt die Domain nicht (NXDOMAIN) – nicht registriert, gelöscht oder gesperrt.");

            return ['parent' => $parent, 'delegated' => [], 'glue' => []];
        }

        $records = $answer->authorityOfType('NS') ?: $answer->ofType('NS');
        $delegated = [];
        foreach ($records as $rr) {
            if (strcasecmp(rtrim((string) $rr->name, '.'), $name) === 0) {
                $delegated[] = rtrim(strtolower((string) $rr->nsdname), '.');
            }
        }
        $delegated = array_values(array_unique($delegated));
        sort($delegated);

        $glue = [];
        foreach ([...$answer->additionalOfType('A'), ...$answer->additionalOfType('AAAA')] as $rr) {
            $glue[rtrim(strtolower((string) $rr->name), '.')][] = self::normalizeIp((string) $rr->address);
        }
        ksort($glue);

        $out->data("Delegation ({$parent}, {$server})", array_map(
            fn ($h) => $h.(isset($glue[$h]) ? ' – Glue: '.implode(', ', $glue[$h]) : ''),
            $delegated ?: ['keine'],
        ));

        if ($delegated === []) {
            $out->warning("Keine Delegation bei {$parent} gefunden – die Registry verweist auf keine Nameserver.");

            return ['parent' => $parent, 'delegated' => [], 'glue' => []];
        }

        if ($delegated !== $ns) {
            $out->warning('Delegation bei der Registry ('.implode(', ', $delegated).') weicht von den NS-Records der Zone ('.implode(', ', $ns).') ab.');
        }

        foreach ($delegated as $host) {
            $inBailiwick = str_ends_with($host, '.'.$name);
            if ($inBailiwick && ! isset($glue[$host])) {
                $out->error("{$host}: liegt in der eigenen Domain, aber die Registry hat keinen Glue-Record – die Domain ist nicht auflösbar.");
            } elseif (isset($glue[$host], $ips[$host])) {
                $a = $glue[$host];
                $b = array_map(self::normalizeIp(...), $ips[$host]);
                sort($a);
                sort($b);
                if (array_diff($a, $b) !== []) {
                    $out->warning("{$host}: Glue bei der Registry (".implode(', ', $a).') weicht von der Adresse in DNS ('.implode(', ', $b).') ab.');
                }
            }
        }

        foreach ($glue as &$addresses) {
            sort($addresses);
        }
        unset($addresses);

        return ['parent' => $parent, 'delegated' => $delegated, 'glue' => $glue];
    }

    /** Einheitliche Schreibweise, damit z. B. 2001:db8::1 und 2001:db8:0:0:0:0:0:1 gleich sind. */
    private static function normalizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? $ip : (string) inet_ntop($packed);
    }
}
