<?php

namespace App\Services\Lookup;

use App\Support\Dns\Dnssec;
use App\Support\Dns\NameserverProbe;
use NetDNS2\RR;

/** DNSSEC-Signaturen (RRSIG) eines Records sowie DS beim Parent im Abgleich mit den DNSKEYs. */
class DnssecLookup
{
    public const TYPES = ['A', 'AAAA', 'MX', 'TXT', 'NS', 'SOA', 'DNSKEY', 'CAA'];

    public function __construct(
        private readonly NameserverProbe $probe,
        private readonly ZoneFinder $zones,
    ) {}

    /** @return list<array> */
    public function rrsig(string $host, string $type = 'A'): array
    {
        $type = in_array(strtoupper($type), self::TYPES, true) ? strtoupper($type) : 'A';
        $answer = $this->probe->configured()->query($host, $type, true);
        $records = $answer->ofType($type);
        $sigs = $answer->ofType('RRSIG');

        $summary = ToolTable::make('Antwort', "{$host} {$type} mit DNSSEC-Abfrage (DO-Bit)")
            ->column('Records')->column('Signaturen')->column('Validiert (AD-Flag)', badge: true)
            ->row([
                $records ? array_map(fn (RR $rr) => trim(preg_replace('/^\S+\s+\d+\s+IN\s+\S+\s+/', '', (string) $rr)), $records) : 'keine',
                (string) count($sigs),
                $answer->authenticated ? 'ok' : ($sigs ? 'warning' : 'info'),
            ]);

        $table = ToolTable::make('Signaturen (RRSIG)')
            ->column('Typ')->column('Algorithmus')->column('Key-Tag', mono: true)->column('Signiert von')->column('Gültig ab')->column('Gültig bis')->column('Status', badge: true)
            ->empty($records ? 'Keine Signaturen – die Zone ist nicht mit DNSSEC signiert.' : 'Keine Records dieses Typs vorhanden.');

        foreach ($sigs as $sig) {
            $from = Dnssec::time((string) $sig->sigincep);
            $to = Dnssec::time((string) $sig->sigexp);
            $hours = $to ? now()->diffInHours($to, false) : null;
            $status = match (true) {
                $to === null => 'unknown',
                $hours < 0 || ($from && $from->isFuture()) => 'error',
                $hours < 72 => 'warning',
                default => 'ok',
            };
            $tz = config('app.timezone');
            $table->row([
                (string) $sig->typecovered,
                Dnssec::algorithmName($sig->algorithm->value),
                (int) $sig->keytag,
                rtrim((string) $sig->signname, '.'),
                $from?->timezone($tz)->format('d.m.Y H:i'),
                $to ? $to->timezone($tz)->format('d.m.Y H:i').' ('.$to->diffForHumans().')' : null,
                $status,
            ]);
        }

        return [$summary->toArray(), $table->toArray()];
    }

    /** @return list<array> */
    public function ds(string $host): array
    {
        [$zone] = $this->zones->find($host);
        $zone ??= $host;
        $dns = $this->probe->configured();

        $ds = $dns->query($zone, 'DS', true)->ofType('DS');
        $keys = $dns->query($zone, 'DNSKEY', true)->ofType('DNSKEY');
        $keyTags = [];
        foreach ($keys as $key) {
            $keyTags[Dnssec::keyTag($key)] = $key;
        }

        $dsTable = ToolTable::make('DS-Records in der Parent-Zone', "Zone {$zone} – vom Registrar an die Registry übermittelt")
            ->column('Key-Tag', mono: true)->column('Algorithmus')->column('Digest-Typ')->column('Digest', mono: true)->column('Passender DNSKEY', badge: true)
            ->empty('Kein DS-Record – die Zone ist beim Parent nicht als signiert eingetragen.');

        foreach ($ds as $rr) {
            $digestType = match ($rr->digesttype->value) {
                1 => 'SHA-1 (1)', 2 => 'SHA-256 (2)', 4 => 'SHA-384 (4)', default => (string) $rr->digesttype->value
            };
            $dsTable->row([(int) $rr->keytag, Dnssec::algorithmName($rr->algorithm->value), $digestType, strtoupper((string) $rr->digest), isset($keyTags[(int) $rr->keytag]) ? 'ok' : 'error']);
        }

        $keyTable = ToolTable::make('DNSKEY-Records in der Zone', 'Zum Abgleich: der DS muss auf einen KSK (Flags 257) zeigen')
            ->column('Key-Tag', mono: true)->column('Rolle')->column('Flags')->column('Algorithmus')->column('Im DS', badge: true)
            ->empty('Keine DNSKEY-Records – die Zone ist nicht signiert.');

        $dsTags = array_map(fn ($rr) => (int) $rr->keytag, $ds);
        foreach ($keyTags as $tag => $key) {
            $ksk = ((int) $key->flags & 1) === 1;
            $keyTable->row([$tag, $ksk ? 'KSK (Key Signing Key)' : 'ZSK (Zone Signing Key)', (int) $key->flags, Dnssec::algorithmName($key->algorithm->value), in_array($tag, $dsTags, true) ? 'ok' : ($ksk ? 'warning' : 'info')]);
        }

        return [$dsTable->toArray(), $keyTable->toArray()];
    }
}
