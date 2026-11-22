<?php

namespace App\Checks;

use App\Models\AppSetting;
use App\Models\Domain;
use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;
use NetDNS2\RR;
use Throwable;

/**
 * Gemeinsamer Kontext eines Prüflaufs: Domain, Resolver, Einstellungen und
 * Zwischenergebnisse, die mehrere Prüfungen brauchen (MX-Hosts, deren IPs, DMARC).
 */
class CheckContext
{
    private array $memo = [];

    public function __construct(
        public readonly Domain $domain,
        public readonly DnsResolver $dns,
        public readonly DnsResolver $rblDns,
        public readonly AppSetting $settings,
    ) {}

    public function name(): string
    {
        return $this->domain->name;
    }

    private function remember(string $key, callable $fn): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $fn();
        }

        return $this->memo[$key];
    }

    /**
     * Ergebnis teurer Abfragen, die mehrere Prüfungen brauchen (z. B. STARTTLS-Zertifikat
     * für TLS-MX und DANE), einmal pro Lauf. Ausnahmen werden mitgespeichert und erneut geworfen.
     */
    public function cached(string $key, callable $fn): mixed
    {
        $result = $this->remember('cached:'.$key, function () use ($fn) {
            try {
                return ['value' => $fn()];
            } catch (Throwable $e) {
                return ['error' => $e];
            }
        });

        if (isset($result['error'])) {
            throw $result['error'];
        }

        return $result['value'];
    }

    /** @return list<array{pref:int, host:string}> */
    public function mx(): array
    {
        return $this->remember('mx', fn () => $this->dns->mx($this->name()));
    }

    /** Null-MX nach RFC 7505 („0 .“) = Domain empfängt keine Mails. */
    public function hasNullMx(): bool
    {
        $mx = $this->mx();

        return count($mx) === 1 && $mx[0]['host'] === '';
    }

    /** Null-MX steht neben weiteren MX-Records (verletzt RFC 7505). */
    public function hasMixedNullMx(): bool
    {
        $mx = $this->mx();

        return count($mx) > 1 && in_array('', array_column($mx, 'host'), true);
    }

    /** Domain empfängt Mail: mindestens ein MX und kein (alleiniger) Null-MX. */
    public function receivesMail(): bool
    {
        return $this->mxHosts() !== [] && ! $this->hasNullMx();
    }

    /** SPF-Record der Domain (erster) oder null. */
    public function spfRecord(): ?string
    {
        return $this->remember('spf', fn () => SpfCheck::records($this->dns, $this->name())[0] ?? null);
    }

    /**
     * Domain versendet Mail – außer sie empfängt keine (kein MX/Null-MX) und verbietet
     * per SPF jeden Versand („v=spf1 -all“ ohne weitere Mechanismen).
     */
    public function sendsMail(): bool
    {
        $spf = $this->spfRecord();
        $noSending = $spf !== null && preg_match('/^v=spf1\s+-all$/i', preg_replace('/\s+/', ' ', trim($spf)));

        return ! ($noSending && ! $this->receivesMail());
    }

    /** Die Zone der Domain ist per DNSSEC validiert (AD-Flag des Resolvers). */
    public function dnssecValidated(): bool
    {
        try {
            return $this->dns->query($this->name(), 'SOA', true)->authenticated;
        } catch (DnsException) {
            return false;
        }
    }

    /**
     * TLSA-Records (DANE) unter _25._tcp eines MX-Hosts; geteilt von DNSSEC- und DANE-Check.
     *
     * @return array{records: list<RR>, authenticated: bool}
     */
    public function tlsa(string $host): array
    {
        return $this->cached('tlsa:'.$host, function () use ($host) {
            $answer = $this->dns->query("_25._tcp.{$host}", 'TLSA', true);

            return ['records' => $answer->ofType('TLSA'), 'authenticated' => $answer->authenticated];
        });
    }

    /** MX-Hosts mit veröffentlichten TLSA-Records (Abfragefehler zählen nicht). @return list<string> */
    public function mxHostsWithTlsa(): array
    {
        if (! $this->receivesMail()) {
            return [];
        }

        return array_values(array_filter($this->mxHosts(), function (string $host) {
            try {
                return $this->tlsa($host)['records'] !== [];
            } catch (DnsException) {
                return false;
            }
        }));
    }

    /** @return list<string> */
    public function mxHosts(): array
    {
        return array_values(array_filter(array_column($this->mx(), 'host')));
    }

    /** @return array<string, list<string>> host => IPs */
    public function mxIps(): array
    {
        return $this->remember('mx_ips', function () {
            $map = [];
            foreach ($this->mxHosts() as $host) {
                try {
                    $map[$host] = $this->dns->ips($host);
                } catch (DnsException) {
                    $map[$host] = [];
                }
            }

            return $map;
        });
    }

    /** Zusätzlich gepflegte ausgehende IPs (z. B. eigener Mailserver). @return list<string> */
    public function extraIps(): array
    {
        return array_values(array_filter($this->domain->extra_ips ?? [], fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP)));
    }

    /** Geparster DMARC-Record der Domain oder null. */
    public function dmarc(): ?array
    {
        return $this->remember('dmarc', function () {
            $records = array_values(array_filter(
                $this->dns->txt('_dmarc.'.$this->name()),
                fn ($t) => preg_match('/^v=DMARC1\s*(;|$)/i', trim($t)),
            ));

            return count($records) === 1 ? Tags::parse($records[0]) : null;
        });
    }
}
