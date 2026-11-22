<?php

namespace App\Services\Lookup;

use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;
use App\Support\Dns\NameserverProbe;

/** PTR-Abfrage für IPs (oder alle IPs eines Hostnamens) inklusive Forward-Confirmed rDNS. */
class ReverseLookup
{
    public function __construct(private readonly NameserverProbe $probe) {}

    /** @return list<array> */
    public function ptr(string $input): array
    {
        $dns = $this->probe->configured();
        $ips = filter_var($input, FILTER_VALIDATE_IP) ? [$input] : $this->resolve($dns, $input);

        $table = ToolTable::make('Reverse DNS (PTR)', filter_var($input, FILTER_VALIDATE_IP) ? null : "Adressen von {$input}")
            ->column('IP-Adresse', mono: true)->column('Reverse-Name', mono: true)->column('PTR')->column('Vorwärts-Auflösung', mono: true)->column('FCrDNS', badge: true)
            ->empty("Zu {$input} wurden keine IP-Adressen gefunden.");

        foreach ($ips as $ip) {
            try {
                $ptrs = $dns->ptr($ip);
            } catch (DnsException $e) {
                $table->row([$ip, DnsResolver::reverseName($ip), DnsLookup::shortError($e), null, 'unknown']);

                continue;
            }
            if ($ptrs === []) {
                $table->row([$ip, DnsResolver::reverseName($ip), 'kein PTR-Record', null, 'error']);

                continue;
            }

            $forward = [];
            $confirmed = false;
            foreach ($ptrs as $ptr) {
                try {
                    $back = $dns->ips($ptr);
                } catch (DnsException) {
                    $back = [];
                }
                $forward = [...$forward, ...$back];
                $confirmed = $confirmed || in_array(inet_pton($ip), array_map(fn ($b) => @inet_pton($b), $back), true);
            }
            $table->row([$ip, DnsResolver::reverseName($ip), $ptrs, $forward ?: 'löst nicht auf', $confirmed ? 'ok' : 'warning']);
        }

        return [$table->toArray()];
    }

    /** @return list<string> */
    private function resolve(DnsResolver $dns, string $host): array
    {
        try {
            return $dns->ips($host);
        } catch (DnsException) {
            return [];
        }
    }
}
