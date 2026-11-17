<?php

namespace Tests\Support;

use App\Support\Dns\DnsAnswer;
use App\Support\Dns\DnsResolver;
use NetDNS2\RR;

/** Resolver mit fest vorgegebenen Zonendaten im Zonefile-Format. */
class FakeDnsResolver extends DnsResolver
{
    /** @var array<string, list<string>> "name|TYPE" => RR-Zeilen */
    private array $zone = [];

    public int $queries = 0;

    /** AD-Flag (DNSSEC validiert) für alle Antworten. */
    public bool $authenticated = false;

    /** AA-Flag (autoritative Antwort) für alle Antworten. */
    public bool $authoritative = false;

    public function __construct(array $records = [])
    {
        parent::__construct([]);
        foreach ($records as $line) {
            $this->add($line);
        }
    }

    public function add(string $line): self
    {
        [$name, , , $type] = preg_split('/\s+/', $line, 5);
        $this->zone[strtolower(rtrim($name, '.')).'|'.strtoupper($type)][] = $line;

        return $this;
    }

    public function query(string $name, string $type, bool $dnssec = false): DnsAnswer
    {
        $this->queries++;
        $lines = $this->zone[strtolower(rtrim($name, '.')).'|'.strtoupper($type)] ?? [];

        return new DnsAnswer(array_map(fn ($l) => RR::fromString($l), $lines), $this->authenticated, false, $this->authoritative);
    }
}
