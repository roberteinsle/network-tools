<?php

namespace App\Support\Dns;

/**
 * Liefert Resolver, die einen einzelnen Nameserver direkt (ohne Rekursion) abfragen.
 * Eigene Klasse, damit Tests sie durch Fakes ersetzen können.
 */
class NameserverProbe
{
    public function resolver(string $ip): DnsResolver
    {
        return new DnsResolver([$ip], 3.0, recurse: false);
    }
}
