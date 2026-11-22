<?php

namespace App\Services\Lookup;

use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;
use App\Support\Dns\NameserverProbe;
use NetDNS2\RR;

/**
 * Einzelne Record-Abfragen für die Werkzeuge (MX, IP, NS, SOA, CNAME, TXT, CAA)
 * über die konfigurierten Resolver. Liefert Tabellen (ToolTable-Arrays).
 */
class RecordLookup
{
    public function __construct(
        private readonly NameserverProbe $probe,
        private readonly ZoneFinder $zones,
        private readonly DnsLookup $ips,
    ) {}

    private function dns(): DnsResolver
    {
        return $this->probe->configured();
    }

    /** @return list<RR> */
    private function records(string $name, string $type): array
    {
        return $this->dns()->query($name, $type)->ofType($type);
    }

    private static function host(mixed $value): string
    {
        return rtrim(strtolower((string) $value), '.');
    }

    /** @return list<array> */
    public function mx(string $host): array
    {
        $dns = $this->dns();
        $table = ToolTable::make('Mailserver (MX)', "MX-Records von {$host}, sortiert nach Priorität (kleinere Zahl = bevorzugt)")
            ->column('Priorität')->column('Mailserver')->column('IPv4', mono: true)->column('IPv6', mono: true)->column('TTL')
            ->empty('Keine MX-Records – Mails werden an den A/AAAA-Record der Domain zugestellt (Implicit MX).');

        $mx = $this->records($host, 'MX');
        usort($mx, fn ($a, $b) => [(int) $a->preference, (string) $a->exchange] <=> [(int) $b->preference, (string) $b->exchange]);

        foreach ($mx as $rr) {
            $target = self::host($rr->exchange);
            if ($target === '') {
                $table->row([(int) $rr->preference, '. (Null-MX: empfängt keine Mails)', null, null, (int) $rr->ttl]);

                continue;
            }
            try {
                $v4 = $dns->a($target);
                $v6 = $dns->aaaa($target);
            } catch (DnsException $e) {
                $v4 = [DnsLookup::shortError($e)];
                $v6 = [];
            }
            $table->row([(int) $rr->preference, $target, $v4 ?: '–', $v6 ?: '–', (int) $rr->ttl]);
        }

        return [$table->toArray()];
    }

    /** IPs laut autoritativen Nameservern und öffentlichen Resolvern. @return list<array> */
    public function ip(string $host): array
    {
        $result = $this->ips->lookup($host);
        $tables = [];

        foreach (['authoritative' => ['Autoritative Nameserver', $result['zone'] ? "Zone {$result['zone']} – Antwort direkt von den Nameservern der Domain" : 'Keine Zone mit Nameservern gefunden.', 'Nameserver'],
            'public' => ['Öffentliche DNS-Resolver', 'Antwort der zehn verbreitetsten öffentlichen Resolver (inkl. Cache)', 'Anbieter']] as $key => [$heading, $description, $label]) {
            $table = ToolTable::make($heading, $description)
                ->column($label)->column('Abgefragt', mono: true)->column('IPv4 (A)', mono: true)->column('IPv6 (AAAA)', mono: true)->column('Hinweis');
            foreach ($result[$key] as $r) {
                $table->row([$r['server'], $r['ip'], $r['a'] ?: '–', $r['aaaa'] ?: '–', $r['error'] ?? ($r['cname'] ? 'CNAME → '.$r['cname'] : null)]);
            }
            $tables[] = $table->toArray();
        }

        return $tables;
    }

    /** Zuständige Nameserver der Zone mit Adressen und SOA-Serial je Server. @return list<array> */
    public function ns(string $host): array
    {
        [$zone, $nameservers] = $this->zones->find($host);
        $table = ToolTable::make('Zuständige Nameserver', $zone ? "Zone {$zone}" : null)
            ->column('Nameserver')->column('IPv4', mono: true)->column('IPv6', mono: true)->column('SOA-Serial', mono: true)->column('Status', badge: true)
            ->empty('Keine Zone mit Nameservern gefunden.');

        foreach ($nameservers as $ns) {
            $v4 = array_values(array_filter($ns['ips'], fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
            $serial = null;
            $status = 'unknown';
            if ($ns['ips'] !== []) {
                try {
                    $answer = $this->probe->resolver($ns['ips'][0])->query($zone, 'SOA');
                    $soa = $answer->ofType('SOA')[0] ?? null;
                    $serial = $soa ? (string) $soa->serial : null;
                    $status = $soa && $answer->authoritative ? 'ok' : 'error';
                    $serial ??= 'nicht autoritativ';
                } catch (DnsException $e) {
                    $serial = DnsLookup::shortError($e);
                    $status = 'error';
                }
            } else {
                $serial = 'Adresse nicht auflösbar';
                $status = 'error';
            }
            $table->row([$ns['name'], $v4 ?: '–', $ns['ipv6'] ?: '–', $serial, $status]);
        }

        return [$table->toArray()];
    }

    /** SOA der Zone mit allen Werten. @return list<array> */
    public function soa(string $host): array
    {
        [$zone] = $this->zones->find($host);
        $table = ToolTable::make('Zonen-Informationen (SOA)', $zone ? "Zone {$zone}" : null)
            ->column('Feld')->column('Wert', mono: true)->column('Bedeutung')
            ->empty('Kein SOA-Record gefunden.');

        $soa = $zone ? ($this->records($zone, 'SOA')[0] ?? null) : null;
        if ($soa) {
            $admin = self::host($soa->rname);
            // NetDNS2 liefert die Mailbox je nach Quelle als „a@b.c“ oder im Zonen-Format „a.b.c“
            $email = str_contains($admin, '@') ? $admin : preg_replace('/(?<!\\\\)\./', '@', $admin, 1);
            $fmt = fn (int $s) => self::duration($s) === "{$s} s" ? "{$s} s" : $s.' s ('.self::duration($s).')';
            $table->row(['Primärer Nameserver (MNAME)', self::host($soa->mname), 'Quelle der Zone'])
                ->row(['Admin (RNAME)', $email, 'Kontakt der Zonenverwaltung'])
                ->row(['Serial', (string) $soa->serial, 'Version der Zone; steigt bei jeder Änderung'])
                ->row(['Refresh', $fmt((int) $soa->refresh), 'Wie oft Secondaries nach Änderungen fragen'])
                ->row(['Retry', $fmt((int) $soa->retry), 'Erneuter Versuch nach fehlgeschlagenem Refresh'])
                ->row(['Expire', $fmt((int) $soa->expire), 'Wann Secondaries die Zone ohne Primary verwerfen'])
                ->row(['Minimum (Negativ-TTL)', $fmt((int) $soa->minimum), 'Cache-Dauer für „Name existiert nicht“'])
                ->row(['TTL des SOA', $fmt((int) $soa->ttl), null]);
        }

        return [$table->toArray()];
    }

    /** CNAME-Kette ab dem Namen. @return list<array> */
    public function cname(string $host): array
    {
        $table = ToolTable::make('Alias-Ziel (CNAME)', "Kette ab {$host}")
            ->column('Name')->column('Zeigt auf')->column('TTL')
            ->empty("{$host} ist kein Alias (kein CNAME-Record).");

        $name = $host;
        $seen = [];
        for ($i = 0; $i < 10; $i++) {
            $rr = collect($this->records($name, 'CNAME'))->first(fn ($r) => strcasecmp(self::host($r->name), $name) === 0);
            if (! $rr) {
                break;
            }
            $target = self::host($rr->cname);
            $table->row([$name, $target, (int) $rr->ttl]);
            if (isset($seen[$target])) {
                $table->row([$target, 'Schleife – der Alias zeigt auf sich selbst zurück', null]);
                break;
            }
            $seen[$name] = true;
            $name = $target;
        }

        $sections = [$table->toArray()];
        if ($name !== $host) {
            $final = $this->ips->ask($this->dns(), $name);
            $sections[] = ToolTable::make('Endgültiges Ziel', $name)
                ->column('IPv4', mono: true)->column('IPv6', mono: true)->column('Hinweis')
                ->row([$final['a'] ?: '–', $final['aaaa'] ?: '–', $final['error']])
                ->toArray();
        }

        return $sections;
    }

    /** @return list<array> */
    public function txt(string $host): array
    {
        $table = ToolTable::make('Text-Records (TXT)', $host)
            ->column('Art')->column('Inhalt', mono: true)->column('TTL')
            ->empty('Keine TXT-Records.');

        foreach ($this->records($host, 'TXT') as $rr) {
            $text = implode('', array_map(fn ($t) => $t->value(), $rr->text));
            $table->row([self::txtKind($text), $text, (int) $rr->ttl]);
        }

        return [$table->toArray()];
    }

    /** CAA des Namens oder des nächsten Elternnamens mit CAA (RFC 8659). @return list<array> */
    public function caa(string $host): array
    {
        $labels = explode('.', $host);
        $found = [];
        $owner = $host;
        for ($i = 0; $i < count($labels) - 1 && $found === []; $i++) {
            $owner = implode('.', array_slice($labels, $i));
            $found = $this->records($owner, 'CAA');
        }

        $table = ToolTable::make('Erlaubte Zertifizierungsstellen (CAA)', $found ? ($owner === $host ? "CAA an {$host}" : "Geerbt von {$owner} (an {$host} selbst steht kein CAA)") : null)
            ->column('Flags')->column('Tag')->column('Wert', mono: true)->column('Bedeutung')->column('TTL')
            ->empty('Kein CAA-Record – jede Zertifizierungsstelle darf Zertifikate ausstellen.');

        foreach ($found as $rr) {
            $tag = strtolower($rr->tag->value());
            $value = $rr->value->value();
            $meaning = match ($tag) {
                'issue' => $value === ';' ? 'Keine CA darf Zertifikate ausstellen' : 'Darf Zertifikate ausstellen',
                'issuewild' => $value === ';' ? 'Keine Wildcard-Zertifikate' : 'Darf Wildcard-Zertifikate ausstellen',
                'iodef' => 'Meldeadresse für Verstöße',
                default => 'Unbekanntes Tag',
            };
            $table->row([(int) $rr->flags, $tag, $value, $meaning, (int) $rr->ttl]);
        }

        return [$table->toArray()];
    }

    public static function txtKind(string $text): string
    {
        $t = strtolower($text);

        return match (true) {
            str_starts_with($t, 'v=spf1') => 'SPF',
            str_starts_with($t, 'v=dmarc1') => 'DMARC',
            str_starts_with($t, 'v=dkim1') => 'DKIM',
            str_starts_with($t, 'v=stsv1') => 'MTA-STS',
            str_starts_with($t, 'v=tlsrptv1') => 'TLS-RPT',
            str_starts_with($t, 'v=bimi1') => 'BIMI',
            str_starts_with($t, 'google-site-verification=') => 'Google-Verifizierung',
            str_starts_with($t, 'ms=') => 'Microsoft-365-Verifizierung',
            str_starts_with($t, 'facebook-domain-verification=') => 'Meta-Verifizierung',
            str_starts_with($t, 'apple-domain-verification=') => 'Apple-Verifizierung',
            str_contains($t, '-domain-verification=') || str_contains($t, '-site-verification=') => 'Verifizierung',
            default => 'Sonstiges',
        };
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 && $seconds % 86400 === 0 => ($seconds / 86400).' Tag(e)',
            $seconds >= 3600 && $seconds % 3600 === 0 => ($seconds / 3600).' Std.',
            $seconds >= 60 && $seconds % 60 === 0 => ($seconds / 60).' Min.',
            default => $seconds.' s',
        };
    }
}
