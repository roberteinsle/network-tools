<?php

namespace App\Services\Lookup;

use App\Support\Dns\DnsAnswer;
use App\Support\Dns\DnsException;
use App\Support\Dns\NameserverProbe;
use NetDNS2\RR;
use NetDNS2\RR\A;
use NetDNS2\RR\CNAME;
use NetDNS2\RR\NS;
use NetDNS2\RR\SOA;

/**
 * Iterative Auflösung von den Root-Servern abwärts (wie „dig +trace“): fragt jede
 * Ebene ohne Rekursion und folgt den Verweisen, bis eine Antwort kommt oder es hakt.
 */
class DnsTrace
{
    /** IPv4-Adressen der Root-Server a–m. */
    public const ROOT_SERVERS = [
        'a.root-servers.net' => '198.41.0.4',
        'b.root-servers.net' => '170.247.170.2',
        'c.root-servers.net' => '192.33.4.12',
        'd.root-servers.net' => '199.7.91.13',
        'e.root-servers.net' => '192.203.230.10',
        'f.root-servers.net' => '192.5.5.241',
        'g.root-servers.net' => '192.112.36.4',
        'h.root-servers.net' => '198.97.190.53',
        'i.root-servers.net' => '192.36.148.17',
        'j.root-servers.net' => '192.58.128.30',
        'k.root-servers.net' => '193.0.14.129',
        'l.root-servers.net' => '199.7.83.42',
        'm.root-servers.net' => '202.12.27.33',
    ];

    private const MAX_STEPS = 12;

    public function __construct(private readonly NameserverProbe $probe) {}

    /** @return list<array> */
    public function trace(string $host): array
    {
        $table = ToolTable::make('Auflösung von den Root-Servern abwärts', "Abfrage: {$host} A, jede Ebene ohne Rekursion")
            ->column('Ebene')->column('Gefragter Server')->column('Antwort')->column('Dauer')->column('Status', badge: true);

        $zone = '.';
        $servers = self::ROOT_SERVERS;
        $done = false;

        for ($step = 0; $step < self::MAX_STEPS && ! $done; $step++) {
            [$name, $ip, $answer, $ms, $errors] = $this->askAny($servers, $host);

            foreach ($errors as [$errName, $errIp, $msg, $errMs]) {
                $table->row([$zone, [$errName, $errIp], $msg, "{$errMs} ms", 'warning']);
            }
            if ($answer === null) {
                $table->row([$zone, '–', 'Kein Nameserver dieser Ebene antwortet – hier hakt die Auflösung.', null, 'error']);
                break;
            }

            $server = [$name, $ip];
            $records = [...$answer->ofType('A'), ...$answer->ofType('CNAME')];

            if ($answer->nxdomain) {
                $table->row([$zone, $server, 'NXDOMAIN – der Name existiert in dieser Zone nicht.', "{$ms} ms", 'error']);
                break;
            }

            if ($records !== []) {
                $lines = array_map(fn (RR $rr) => $rr instanceof CNAME
                    ? 'CNAME → '.rtrim((string) $rr->cname, '.')
                    : 'A '.(string) $rr->address, $records);
                $table->row([$zone, $server, $lines, "{$ms} ms", $answer->authoritative ? 'ok' : 'warning']);
                if (! $answer->authoritative) {
                    $table->row([$zone, $server, 'Antwort ohne AA-Flag – der Server ist für die Zone nicht autoritativ (Lame Delegation).', null, 'warning']);
                }
                $done = true;
                break;
            }

            $ns = array_values(array_filter($answer->authority, fn (RR $rr) => $rr instanceof NS));
            if ($ns === []) {
                $soa = array_filter($answer->authority, fn (RR $rr) => $rr instanceof SOA);
                $table->row([$zone, $server, $soa
                    ? 'Name existiert, hat aber keinen A-Record (NODATA).'
                    : 'Weder Antwort noch Verweis – unerwartete Antwort.', "{$ms} ms", $soa ? 'info' : 'error']);
                break;
            }

            $child = rtrim(strtolower((string) $ns[0]->name), '.');
            if ($child === '' || ($zone !== '.' && (! str_ends_with($child, '.'.$zone) || $child === $zone))) {
                $table->row([$zone, $server, "Verweis auf „{$child}“ führt nicht tiefer – Schleife oder Fehlkonfiguration.", "{$ms} ms", 'error']);
                break;
            }

            $next = $this->nextServers($ns, $answer->additional);
            $nsNames = array_map(fn (RR $rr) => rtrim(strtolower((string) $rr->nsdname), '.'), $ns);
            $table->row([$zone, $server, array_merge(["Verweis auf {$child}:"], $nsNames), "{$ms} ms", $next === [] ? 'error' : 'ok']);

            if ($next === []) {
                $table->row([$child, '–', 'Adressen der Nameserver nicht auflösbar – hier hakt die Auflösung.', null, 'error']);
                break;
            }

            $zone = $child;
            $servers = $next;
        }

        if (! $done && $step >= self::MAX_STEPS) {
            $table->row([$zone, '–', 'Zu viele Verweise – Abbruch.', null, 'error']);
        }

        return [$table->toArray()];
    }

    /**
     * Fragt die Server der Ebene der Reihe nach, bis einer antwortet (höchstens drei Versuche).
     *
     * @param  array<string, string>  $servers
     * @return array{0:?string, 1:?string, 2:?DnsAnswer, 3:int, 4:list<array>}
     */
    private function askAny(array $servers, string $host): array
    {
        $errors = [];
        foreach (array_slice($servers, 0, 3, true) as $name => $ip) {
            $t0 = hrtime(true);
            try {
                $answer = $this->probe->resolver($ip)->query($host, 'A');

                return [$name, $ip, $answer, (int) ((hrtime(true) - $t0) / 1e6), $errors];
            } catch (DnsException $e) {
                $errors[] = [$name, $ip, DnsLookup::shortError($e), (int) ((hrtime(true) - $t0) / 1e6)];
            }
        }

        return [null, null, null, 0, $errors];
    }

    /**
     * Adressen der Nameserver aus dem Verweis: Glue aus der Additional-Sektion, sonst per Resolver.
     *
     * @param  list<RR>  $ns
     * @param  list<RR>  $additional
     * @return array<string, string>
     */
    private function nextServers(array $ns, array $additional): array
    {
        $glue = [];
        foreach ($additional as $rr) {
            if ($rr instanceof A) {
                $glue[rtrim(strtolower((string) $rr->name), '.')] ??= (string) $rr->address;
            }
        }

        $servers = [];
        foreach ($ns as $rr) {
            $name = rtrim(strtolower((string) $rr->nsdname), '.');
            if (isset($glue[$name])) {
                $servers[$name] = $glue[$name];

                continue;
            }
            try {
                $ip = $this->probe->configured()->a($name)[0] ?? null;
            } catch (DnsException) {
                $ip = null;
            }
            if ($ip) {
                $servers[$name] = $ip;
            }
        }

        return $servers;
    }
}
