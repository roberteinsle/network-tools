<?php

namespace App\Services\Lookup;

use App\Support\Dns\DnsException;
use App\Support\Dns\NameserverProbe;
use NetDNS2\RR;

/** Sucht zu einem Hostnamen die Zone (nächstes Label mit NS-Records) und deren Nameserver. */
class ZoneFinder
{
    public function __construct(private readonly NameserverProbe $probe) {}

    /** @return array{0:?string, 1:list<array{name:string, ips:list<string>, ipv6:list<string>}>} */
    public function find(string $host): array
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
                    $v4 = $dns->a($ns);
                    $v6 = $dns->aaaa($ns);
                } catch (DnsException) {
                    $v4 = $v6 = [];
                }
                // für Abfragen IPv4 bevorzugen, IPv6 nur als Ausweg
                $nameservers[] = ['name' => $ns, 'ips' => $v4 !== [] ? $v4 : $v6, 'ipv6' => $v6];
            }
            usort($nameservers, fn ($a, $b) => $a['name'] <=> $b['name']);

            return [$name, $nameservers];
        }

        return [null, []];
    }
}
