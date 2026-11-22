<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Support\Dns\DnsException;
use App\Support\Tls\CertificateInfo;
use App\Support\Tls\StartTlsUnsupported;
use App\Support\Tls\TlsInspector;
use RuntimeException;

/**
 * DANE für SMTP (RFC 7672): TLSA-Records unter _25._tcp.<mx>, DNSSEC-Validierung und –
 * wenn die STARTTLS-Prüfung (Port 25) aktiv ist – Abgleich mit dem Zertifikat des MX.
 */
class DaneCheck implements Check
{
    public function __construct(private readonly TlsInspector $tls) {}

    public function type(): CheckType
    {
        return CheckType::Dane;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        if (! $ctx->receivesMail()) {
            return $out->notApplicable('Domain empfängt keine Mail (kein MX bzw. Null-MX) – DANE nicht relevant.')->state([]);
        }
        $hosts = $ctx->mxHosts();

        $tlsa = [];
        $validated = [];
        $failed = [];
        foreach ($hosts as $host) {
            try {
                $answer = $ctx->tlsa($host);
            } catch (DnsException $e) {
                // Manche Anbieter (z. B. Exchange Online) beantworten TLSA-Abfragen mit SERVFAIL
                $failed[$host] = $e->getMessage();
                $out->data("_25._tcp.{$host}", 'Abfrage fehlgeschlagen');

                continue;
            }
            $rrs = $answer['records'];
            if ($rrs !== []) {
                $tlsa[$host] = array_map(fn ($rr) => [
                    'usage' => (int) $rr->cert_usage,
                    'selector' => (int) $rr->selector,
                    'matching' => (int) $rr->matching_type,
                    'data' => strtolower((string) $rr->certificate),
                ], $rrs);
                $validated[$host] = $answer['authenticated'];
            }
        }

        if ($tlsa === []) {
            return $out->info('Kein DANE eingerichtet (optional, schützt eingehende Mails vor TLS-Downgrade und falschen Zertifikaten).')->state([]);
        }

        if (! $ctx->dnssecValidated()) {
            // Ursache ist DNSSEC der Domain – dort gemeldet, hier kein zweiter Befund
            foreach ($tlsa as $host => $records) {
                $out->data("_25._tcp.{$host}", array_map(fn ($r) => "{$r['usage']} {$r['selector']} {$r['matching']} ".mb_strimwidth($r['data'], 0, 24, '…'), $records));
            }

            return $out->info('DANE ist veröffentlicht, wirkt aber nicht, weil die Domain-Zone nicht per DNSSEC validiert ist – siehe Prüfung DNSSEC.')
                ->state(array_map(fn ($records) => array_map(fn ($r) => "{$r['usage']} {$r['selector']} {$r['matching']} {$r['data']}", $records), $tlsa));
        }

        $state = [];
        foreach ($hosts as $host) {
            if (isset($failed[$host])) {
                $out->add(CheckStatus::Unknown, "{$host}: TLSA nicht abfragbar – {$failed[$host]}");

                continue;
            }
            if (! isset($tlsa[$host])) {
                $out->warning("{$host}: kein TLSA-Record, obwohl andere MX-Hosts DANE nutzen.");
                $state[$host] = 'none';

                continue;
            }

            $records = $tlsa[$host];
            $out->data("_25._tcp.{$host}", array_map(fn ($r) => "{$r['usage']} {$r['selector']} {$r['matching']} ".mb_strimwidth($r['data'], 0, 24, '…'), $records));

            if (! $validated[$host]) {
                $out->warning("{$host}: TLSA-Records ohne gültige DNSSEC-Signatur – sendende Server ignorieren DANE.");
            }

            foreach ($records as $r) {
                if (! in_array($r['usage'], [0, 1, 2, 3], true) || ! in_array($r['selector'], [0, 1], true) || ! in_array($r['matching'], [0, 1, 2], true)) {
                    $out->error("{$host}: ungültiger TLSA-Record {$r['usage']} {$r['selector']} {$r['matching']}.");
                } elseif ($r['usage'] < 2) {
                    $out->warning("{$host}: TLSA-Usage {$r['usage']} (PKIX) ist für SMTP nicht vorgesehen (RFC 7672) – empfohlen ist 3 1 1 mit 2 1 1 als Reserve.");
                }
            }

            $lines = array_map(fn ($r) => "{$r['usage']} {$r['selector']} {$r['matching']} {$r['data']}", $records);
            sort($lines);
            $state[$host] = $lines;

            if ($ctx->domain->check_mx_tls) {
                $this->compare($ctx, $out, $host, $records);
            }
        }

        if (! $ctx->domain->check_mx_tls) {
            $out->info('Abgleich mit dem Zertifikat nur mit aktivierter STARTTLS-Prüfung der MX-Hosts (Port 25).');
        }

        if (! $out->status()->isProblem()) {
            $out->summary('DANE für '.count($tlsa).' MX-Host(s)'.($ctx->domain->check_mx_tls ? ', Zertifikate passen' : ''));
        }

        return $out->state($state);
    }

    private function compare(CheckContext $ctx, CheckOutcome $out, string $host, array $records): void
    {
        try {
            $cert = $ctx->cached('starttls:'.$host, fn () => $this->tls->starttls($host));
        } catch (StartTlsUnsupported) {
            $out->error("{$host}: DANE veröffentlicht, aber kein STARTTLS – DANE-Absender stellen keine Mails zu.");

            return;
        } catch (RuntimeException $e) {
            $out->add(CheckStatus::Unknown, "{$host}: Zertifikat nicht abrufbar – {$e->getMessage()}");

            return;
        }

        $match = self::match($records, $cert);
        $match !== null
            ? $out->ok("{$host}: Zertifikat passt zu TLSA {$match['usage']} {$match['selector']} {$match['matching']}.")
            : $out->error("{$host}: kein TLSA-Record passt zum Zertifikat – DANE-validierende Absender stellen keine Mails zu.");
    }

    /** Erster passender TLSA-Record oder null. */
    public static function match(array $records, CertificateInfo $cert): ?array
    {
        $chain = $cert->chain !== [] ? $cert->chain : array_filter([$cert->pem]);

        foreach ($records as $r) {
            // Usage 1/3: Serverzertifikat; 0/2: ein Zertifikat der Kette (Trust Anchor)
            $candidates = in_array($r['usage'], [1, 3], true)
                ? array_filter([$cert->pem])
                : (array_slice($chain, 1) ?: $chain);

            foreach ($candidates as $pem) {
                $data = $r['selector'] === 0 ? self::der($pem) : self::spki($pem);
                if ($data === null) {
                    continue;
                }
                $hash = match ($r['matching']) {
                    0 => bin2hex($data),
                    1 => hash('sha256', $data),
                    2 => hash('sha512', $data),
                    default => null,
                };
                if ($hash !== null && hash_equals($r['data'], $hash)) {
                    return $r;
                }
            }
        }

        return null;
    }

    private static function der(string $pem): ?string
    {
        $body = preg_replace('/-----[^-]+-----|\s/', '', $pem);
        $der = base64_decode((string) $body, true);

        return $der === false ? null : $der;
    }

    private static function spki(string $pem): ?string
    {
        $key = @openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;

        return $details ? self::der($details['key']) : null;
    }
}
