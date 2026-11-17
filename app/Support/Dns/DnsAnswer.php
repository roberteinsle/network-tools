<?php

namespace App\Support\Dns;

use NetDNS2\RR;

final class DnsAnswer
{
    /** @param  list<RR>  $records */
    public function __construct(
        public readonly array $records,
        public readonly bool $authenticated = false,
        public readonly bool $nxdomain = false,
        /** AA-Flag: Antwort kommt von einem autoritativen Server für die Zone. */
        public readonly bool $authoritative = false,
        /** @var list<RR> Authority-Sektion (z. B. NS eines Verweises vom Parent) */
        public readonly array $authority = [],
        /** @var list<RR> Additional-Sektion (z. B. Glue-Records) */
        public readonly array $additional = [],
    ) {}

    /** @return list<RR> */
    public function ofType(string $type): array
    {
        $class = '\\NetDNS2\\RR\\'.strtoupper($type);

        return array_values(array_filter($this->records, fn (RR $rr) => $rr instanceof $class));
    }

    /** @return list<RR> */
    public function authorityOfType(string $type): array
    {
        return self::filter($this->authority, $type);
    }

    /** @return list<RR> */
    public function additionalOfType(string $type): array
    {
        return self::filter($this->additional, $type);
    }

    /** @param list<RR> $records @return list<RR> */
    private static function filter(array $records, string $type): array
    {
        $class = '\\NetDNS2\\RR\\'.strtoupper($type);

        return array_values(array_filter($records, fn (RR $rr) => $rr instanceof $class));
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }
}
