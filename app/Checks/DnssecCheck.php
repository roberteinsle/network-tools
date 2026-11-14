<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Dns\DnsException;

/**
 * DNSSEC: DS beim Parent (Registrar), DNSKEY in der Zone und Validierung über das
 * AD-Flag eines validierenden Resolvers (Standard: 1.1.1.1, 8.8.8.8, 9.9.9.9).
 */
class DnssecCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Dnssec;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $name = $ctx->name();

        try {
            $soa = $ctx->dns->query($name, 'SOA', true);
        } catch (DnsException $e) {
            if ($e->isServFail()) {
                return $out->error('Resolver meldet SERVFAIL – vermutlich ungültige DNSSEC-Signatur (bogus). Die Domain ist für validierende Resolver nicht erreichbar.')
                    ->state(['status' => 'bogus']);
            }
            throw $e;
        }

        $ds = $ctx->dns->query($name, 'DS', true)->ofType('DS');
        $keys = $ctx->dns->query($name, 'DNSKEY', true)->ofType('DNSKEY');

        $dsInfo = array_map(fn ($rr) => sprintf('Key-Tag %d, Algorithmus %d, Digest %d', $rr->keytag, $rr->algorithm->value, $rr->digesttype->value), $ds);
        $keyInfo = array_map(fn ($rr) => sprintf('Flags %d (%s), Algorithmus %d', $rr->flags, $rr->flags & 1 ? 'KSK' : 'ZSK', $rr->algorithm->value), $keys);
        $out->data('DS (Parent)', $dsInfo)->data('DNSKEY (Zone)', $keyInfo)->data('AD-Flag', $soa->authenticated);

        if ($ds === [] && $keys === []) {
            return $out->warning('Domain ist nicht mit DNSSEC signiert.')->state(['status' => 'unsigned']);
        }
        if ($ds === []) {
            return $out->warning('Zone ist signiert, aber beim Registrar ist kein DS-Record hinterlegt – Vertrauenskette fehlt.')
                ->state(['status' => 'no-ds', 'keys' => count($keys)]);
        }
        if ($keys === []) {
            return $out->error('DS-Record vorhanden, aber keine DNSKEYs in der Zone – Validierung schlägt fehl.')
                ->state(['status' => 'no-dnskey']);
        }

        $soa->authenticated
            ? $out->ok('DNSSEC aktiv und erfolgreich validiert (AD-Flag gesetzt).')
            : $out->warning('DS und DNSKEY vorhanden, aber der Resolver bestätigt die Validierung nicht (kein AD-Flag).');

        foreach ($ds as $rr) {
            if (in_array($rr->algorithm->value, [1, 3, 5, 6, 7, 12], true)) {
                $out->warning("Veralteter Algorithmus {$rr->algorithm->value} im DS (Key-Tag {$rr->keytag}).");
            }
        }

        $tags = array_map(fn ($rr) => $rr->keytag, $ds);
        sort($tags);

        return $out->state(['status' => $soa->authenticated ? 'secure' : 'unvalidated', 'ds' => $tags]);
    }
}
