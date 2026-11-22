<?php

namespace App\Support\Dns;

use Carbon\CarbonImmutable;
use NetDNS2\RR;

/** Hilfsfunktionen für DNSSEC-Daten (Key-Tag, Signaturzeiten, Algorithmus-Namen). */
final class Dnssec
{
    /** Key-Tag eines DNSKEY nach RFC 4034, Anhang B. */
    public static function keyTag(RR $dnskey): int
    {
        $rdata = pack('nCC', (int) $dnskey->flags, (int) $dnskey->protocol, $dnskey->algorithm->value)
            .(string) base64_decode((string) $dnskey->key);

        $acc = 0;
        $len = strlen($rdata);
        for ($i = 0; $i < $len; $i++) {
            $acc += ($i & 1) ? ord($rdata[$i]) : ord($rdata[$i]) << 8;
        }
        $acc += ($acc >> 16) & 0xFFFF;

        return $acc & 0xFFFF;
    }

    /** Signaturzeit im Format YYYYMMDDHHMMSS (UTC) oder als Unix-Zeit. */
    public static function time(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{14}$/', $value)) {
            return CarbonImmutable::createFromFormat('YmdHis', $value, 'UTC') ?: null;
        }
        if (ctype_digit($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        return null;
    }

    public static function algorithmName(int $algorithm): string
    {
        return match ($algorithm) {
            1 => 'RSAMD5', 3 => 'DSA', 5 => 'RSASHA1', 6 => 'DSA-NSEC3-SHA1', 7 => 'RSASHA1-NSEC3-SHA1',
            8 => 'RSASHA256', 10 => 'RSASHA512', 12 => 'ECC-GOST', 13 => 'ECDSAP256SHA256',
            14 => 'ECDSAP384SHA384', 15 => 'ED25519', 16 => 'ED448',
            default => "Algorithmus {$algorithm}",
        }." ({$algorithm})";
    }

    /** Veraltete Algorithmen nach RFC 8624. */
    public static function isDeprecated(int $algorithm): bool
    {
        return in_array($algorithm, [1, 3, 5, 6, 7, 12], true);
    }
}
