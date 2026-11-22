<?php

namespace App\Services\Lookup;

use App\Enums\PublicResolver;
use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;
use App\Support\Dns\NameserverProbe;
use NetDNS2\RR;

/**
 * Löst einen Hostnamen bei den autoritativen Nameservern seiner Zone und bei den
 * registrierten öffentlichen Resolvern auf und liefert pro Server die IPs (A/AAAA).
 */
class DnsLookup
{
    public function __construct(private readonly NameserverProbe $probe) {}

    /**
     * @return array{zone:?string, authoritative:list<array>, public:list<array>}
     */
    public function lookup(string $host): array
    {
        [$zone, $nameservers] = $this->findZone($host);

        $authoritative = [];
        foreach ($nameservers as $ns) {
            foreach ($ns['ips'] ?: [null] as $ip) {
                $authoritative[] = $ip === null
                    ? ['server' => $ns['name'], 'ip' => '–', 'a' => [], 'aaaa' => [], 'cname' => null, 'error' => 'Adresse des Nameservers nicht auflösbar']
                    : ['server' => $ns['name'], 'ip' => $ip, ...$this->ask($this->probe->resolver($ip), $host)];
            }
        }

        $public = [];
        foreach (PublicResolver::cases() as $resolver) {
            $public[] = [
                'server' => $resolver->label(),
                'ip' => $resolver->ip(),
                ...$this->ask($this->probe->recursive($resolver->ip()), $host),
            ];
        }

        return ['zone' => $zone, 'authoritative' => $authoritative, 'public' => $public];
    }

    /** @return array{a:list<string>, aaaa:list<string>, cname:?string, error:?string} */
    private function ask(DnsResolver $dns, string $host): array
    {
        $result = ['a' => [], 'aaaa' => [], 'cname' => null, 'error' => null];

        try {
            foreach (['A' => 'a', 'AAAA' => 'aaaa'] as $type => $key) {
                $answer = $dns->query($host, $type);
                if ($answer->nxdomain) {
                    $result['error'] = 'NXDOMAIN (Name existiert nicht)';

                    return $result;
                }
                foreach ($answer->ofType($type) as $rr) {
                    $result[$key][] = (string) $rr->address;
                }
                foreach ($answer->ofType('CNAME') as $rr) {
                    $result['cname'] ??= rtrim(strtolower((string) $rr->cname), '.');
                }
            }
        } catch (DnsException $e) {
            $result['error'] = self::shortError($e);
        }

        sort($result['a']);
        sort($result['aaaa']);

        return $result;
    }

    private static function shortError(DnsException $e): string
    {
        return match (true) {
            $e->rcode === 2 => 'SERVFAIL',
            $e->rcode === 5 => 'Abfrage abgelehnt (REFUSED)',
            stripos($e->getMessage(), 'timeout') !== false => 'Keine Antwort (Zeitüberschreitung)',
            default => mb_strimwidth($e->getMessage(), 0, 80, '…'),
        };
    }

    /**
     * Sucht die Zone (nächstes Label mit NS-Records) und die Adressen ihrer Nameserver.
     *
     * @return array{0:?string, 1:list<array{name:string, ips:list<string>}>}
     */
    private function findZone(string $host): array
    {
        $dns = $this->probe->configured();
        $labels = explode('.', $host);

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $name = implode('.', array_slice($labels, $i));
            try {
                $records = array_filter(
                    $dns->query($name, 'NS')->ofType('NS'),
                    fn (RR $rr) => strcasecmp(rtrim((string) $rr->name, '.'), $name) === 0,
                );
            } catch (DnsException) {
                continue;
            }
            if ($records === []) {
                continue;
            }

            $nameservers = [];
            foreach ($records as $rr) {
                $ns = rtrim(strtolower((string) $rr->nsdname), '.');
                try {
                    $ips = $dns->a($ns);
                    $ips = $ips !== [] ? $ips : $dns->aaaa($ns);
                } catch (DnsException) {
                    $ips = [];
                }
                $nameservers[] = ['name' => $ns, 'ips' => $ips];
            }
            usort($nameservers, fn ($a, $b) => $a['name'] <=> $b['name']);

            return [$name, $nameservers];
        }

        return [null, []];
    }
}
