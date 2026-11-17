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
    ) {}

    /** @return list<RR> */
    public function ofType(string $type): array
    {
        $class = '\\NetDNS2\\RR\\'.strtoupper($type);

        return array_values(array_filter($this->records, fn (RR $rr) => $rr instanceof $class));
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }
}
