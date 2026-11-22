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

    /** @var array<string, array{authority: list<string>, additional: list<string>}> Verweise wie vom Parent */
    private array $referrals = [];

    /** Antwort ohne Answer-Sektion, nur mit NS in der Authority und Glue in der Additional-Sektion. */
    public function referral(string $name, string $type, array $authority, array $additional = []): self
    {
        $this->referrals[strtolower(rtrim($name, '.')).'|'.strtoupper($type)] = ['authority' => $authority, 'additional' => $additional];

        return $this;
    }

    /** @var list<string> Namen, für die NXDOMAIN geantwortet wird */
    public array $nxdomain = [];

    public function query(string $name, string $type, bool $dnssec = false): DnsAnswer
    {
        $this->queries++;
        if (in_array(strtolower(rtrim($name, '.')), $this->nxdomain, true)) {
            return new DnsAnswer([], false, true);
        }
        $key = strtolower(rtrim($name, '.')).'|'.strtoupper($type);
        if (isset($this->referrals[$key])) {
            $toRr = fn (array $lines) => array_map(fn ($l) => RR::fromString($l), $lines);

            return new DnsAnswer([], $this->authenticated, false, false, $toRr($this->referrals[$key]['authority']), $toRr($this->referrals[$key]['additional']));
        }
        $lines = $this->zone[$key] ?? [];
        if ($dnssec) {
            // DO-Bit: passende Signaturen (RRSIG über den gefragten Typ) mitliefern
            foreach ($this->zone[strtolower(rtrim($name, '.')).'|RRSIG'] ?? [] as $sig) {
                if (strtoupper(preg_split('/\s+/', $sig)[4] ?? '') === strtoupper($type)) {
                    $lines[] = $sig;
                }
            }
        }

        return new DnsAnswer(array_map(fn ($l) => RR::fromString($l), $lines), $this->authenticated, false, $this->authoritative);
    }
}
