<?php

namespace App\Support\Dns;

use NetDNS2\Exception as NetDnsException;
use NetDNS2\Resolver;
use NetDNS2\RR;

/**
 * Dünner Wrapper um NetDNS2 mit Cache pro Prüflauf.
 * Leere Antwort / NXDOMAIN = leeres Ergebnis, technische Fehler = DnsException.
 */
class DnsResolver
{
    /** @var array<string, DnsAnswer> */
    private array $cache = [];

    private ?Resolver $resolver = null;

    /** @param  list<string>|string  $nameservers  IP-Liste oder Pfad zu resolv.conf */
    public function __construct(
        private readonly array|string $nameservers,
        private readonly float $timeout = 4.0,
        /** false = direkte Abfrage eines autoritativen Servers (RD-Bit aus). */
        private readonly bool $recurse = true,
    ) {}

    public function query(string $name, string $type, bool $dnssec = false): DnsAnswer
    {
        $name = rtrim($name, '.');
        $key = strtolower($name).'|'.strtoupper($type).'|'.($dnssec ? 1 : 0);

        return $this->cache[$key] ??= $this->doQuery($name, strtoupper($type), $dnssec);
    }

    private function doQuery(string $name, string $type, bool $dnssec): DnsAnswer
    {
        $resolver = $this->resolver();
        $resolver->dnssec = $dnssec;

        try {
            $response = $resolver->query($name, $type);
        } catch (NetDnsException $e) {
            $code = $e->getCode();
            if ($code === 3) { // NXDOMAIN
                return new DnsAnswer([], false, true);
            }
            if ($code >= 1 && $code <= 23) {
                throw new DnsException("DNS-Abfrage {$name} {$type} fehlgeschlagen: ".$e->getMessage(), $code);
            }
            throw new DnsException("DNS-Abfrage {$name} {$type} nicht möglich: ".$e->getMessage());
        }

        return new DnsAnswer(
            array_values($response->answer),
            (bool) $response->header->ad,
            false,
            (bool) $response->header->aa,
            array_values($response->authority),
            array_values($response->additional),
        );
    }

    private function resolver(): Resolver
    {
        if ($this->resolver === null) {
            $this->resolver = new Resolver(['nameservers' => $this->nameservers, 'timeout' => $this->timeout]);
            $this->resolver->ns_random = false;
            $this->resolver->recurse = $this->recurse;
        }

        return $this->resolver;
    }

    /** @return list<string> */
    public function a(string $host): array
    {
        return array_map(fn (RR $rr) => (string) $rr->address, $this->query($host, 'A')->ofType('A'));
    }

    /** @return list<string> */
    public function aaaa(string $host): array
    {
        return array_map(fn (RR $rr) => (string) $rr->address, $this->query($host, 'AAAA')->ofType('AAAA'));
    }

    /** @return list<string> */
    public function ips(string $host): array
    {
        return [...$this->a($host), ...$this->aaaa($host)];
    }

    /** @return list<array{pref:int, host:string}> sortiert nach Priorität */
    public function mx(string $domain): array
    {
        $list = array_map(fn (RR $rr) => [
            'pref' => (int) $rr->preference,
            'host' => rtrim(strtolower((string) $rr->exchange), '.'),
        ], $this->query($domain, 'MX')->ofType('MX'));

        usort($list, fn ($a, $b) => [$a['pref'], $a['host']] <=> [$b['pref'], $b['host']]);

        return $list;
    }

    /**
     * SRV-Records (RFC 2782), sortiert nach Priorität und absteigendem Gewicht.
     * Ziel '' = „.“ (Dienst wird ausdrücklich nicht angeboten).
     *
     * @return list<array{priority:int, weight:int, port:int, target:string, ttl:int}>
     */
    public function srv(string $name): array
    {
        $list = array_map(fn (RR $rr) => [
            'priority' => (int) $rr->priority,
            'weight' => (int) $rr->weight,
            'port' => (int) $rr->port,
            'target' => rtrim(strtolower((string) $rr->target), '.'),
            'ttl' => (int) $rr->ttl,
        ], $this->query($name, 'SRV')->ofType('SRV'));

        usort($list, fn ($a, $b) => [$a['priority'], -$a['weight'], $a['target'], $a['port']] <=> [$b['priority'], -$b['weight'], $b['target'], $b['port']]);

        return $list;
    }

    /** @return list<string> Nameserver-Hostnamen, sortiert */
    public function ns(string $domain): array
    {
        $list = array_map(fn (RR $rr) => rtrim(strtolower((string) $rr->nsdname), '.'), $this->query($domain, 'NS')->ofType('NS'));
        sort($list);

        return array_values(array_unique($list));
    }

    /** TXT-Records, Zeichenketten pro Record zusammengefügt. @return list<string> */
    public function txt(string $name): array
    {
        return array_map(
            fn (RR $rr) => implode('', array_map(fn ($t) => $t->value(), $rr->text)),
            $this->query($name, 'TXT')->ofType('TXT'),
        );
    }

    /** CNAME direkt am Namen (falls vorhanden). */
    public function cname(string $name): ?string
    {
        try {
            $records = $this->query($name, 'CNAME')->ofType('CNAME');
        } catch (DnsException) {
            return null; // manche Server (z. B. outlook.com) beantworten CNAME-Abfragen nicht
        }

        foreach ($records as $rr) {
            if (strcasecmp(rtrim((string) $rr->name, '.'), $name) === 0) {
                return rtrim(strtolower((string) $rr->cname), '.');
            }
        }

        return null;
    }

    /** @return list<string> */
    public function ptr(string $ip): array
    {
        return array_map(
            fn (RR $rr) => rtrim(strtolower((string) $rr->ptrdname), '.'),
            $this->query(self::reverseName($ip), 'PTR')->ofType('PTR'),
        );
    }

    public static function reverseName(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $hex = bin2hex((string) inet_pton($ip));

            return implode('.', array_reverse(str_split($hex))).'.ip6.arpa';
        }

        return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
    }
}
