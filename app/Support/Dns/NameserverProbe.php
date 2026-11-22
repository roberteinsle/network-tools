<?php

namespace App\Support\Dns;

use App\Models\AppSetting;

/**
 * Liefert Resolver, die genau einen Server abfragen: autoritativ (ohne Rekursion) oder rekursiv.
 * Eigene Klasse, damit Tests sie durch Fakes ersetzen können.
 */
class NameserverProbe
{
    public function resolver(string $ip): DnsResolver
    {
        return new DnsResolver([$ip], 3.0, recurse: false);
    }

    /** Rekursive Abfrage über die in den Einstellungen konfigurierten Resolver. */
    public function configured(): DnsResolver
    {
        return new DnsResolver(AppSetting::current()->dnsResolvers(), 3.0);
    }

    /** Rekursive Abfrage über genau einen Resolver (z. B. einen öffentlichen DNS-Dienst). */
    public function recursive(string $ip): DnsResolver
    {
        return new DnsResolver([$ip], 2.5);
    }
}
