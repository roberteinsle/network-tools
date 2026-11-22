<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Cloudflare;
use App\Support\Dns\DnsException;
use App\Support\Dns\Dnssec;
use NetDNS2\RR;

/**
 * DNSSEC: DS beim Parent (Registrar), DNSKEY in der Zone und Validierung über das
 * AD-Flag eines validierenden Resolvers (Standard: 1.1.1.1, 8.8.8.8, 9.9.9.9).
 */
class DnssecCheck implements Check
{
    /** Warnung erst bei weniger Restlaufzeit einer Signatur (Stunden). */
    public const EXPIRY_WARNING_HOURS = 6;

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

        // DANE der MX-Hosts wirkt nur mit validierter Domain-Zone – das ist ein DNSSEC-Befund, kein eigener DANE-Befund
        $dane = $soa->authenticated ? [] : $ctx->mxHostsWithTlsa();
        $daneNote = $dane !== [] ? ' DANE (TLSA für '.implode(', ', $dane).') ist veröffentlicht, wird von sendenden Servern aber erst mit validiertem DNSSEC der Domain angewendet.' : '';

        if ($ds === [] && $keys === []) {
            return ($dane !== []
                ? $out->warning('Domain ist nicht mit DNSSEC signiert.'.$daneNote)
                : $out->info('Domain ist nicht mit DNSSEC signiert (optional).'))
                ->state(['status' => 'unsigned', 'dane' => $dane !== []]);
        }
        if ($ds === []) {
            return $out->warning('DNSSEC unvollständig: DS-Record beim Registrar fehlt. Die Zone ist signiert, aber ohne DS gibt es keine Vertrauenskette.'.$daneNote)
                ->state(['status' => 'no-ds', 'keys' => count($keys)]);
        }
        if ($keys === []) {
            return $out->error('DS-Record vorhanden, aber keine DNSKEYs in der Zone – Validierung schlägt fehl.'.$daneNote)
                ->state(['status' => 'no-dnskey']);
        }

        $soa->authenticated
            ? $out->ok('DNSSEC aktiv und erfolgreich validiert (AD-Flag gesetzt).')
            : $out->warning('DS und DNSKEY vorhanden, aber der Resolver bestätigt die Validierung nicht (kein AD-Flag).'.$daneNote);

        foreach ($ds as $rr) {
            if (Dnssec::isDeprecated($rr->algorithm->value)) {
                $out->warning("Veralteter Algorithmus {$rr->algorithm->value} im DS (Key-Tag {$rr->keytag}).");
            }
        }

        // DS beim Parent muss zu einem DNSKEY der Zone passen (gleicher Key-Tag)
        $keyTags = array_map(fn ($rr) => Dnssec::keyTag($rr), $keys);
        $matching = array_filter($ds, fn ($rr) => in_array((int) $rr->keytag, $keyTags, true));
        if ($matching === []) {
            $out->error('Kein DS-Record passt zu einem DNSKEY der Zone (Key-Tags DS: '.implode(', ', array_map(fn ($rr) => $rr->keytag, $ds)).'; DNSKEY: '.implode(', ', $keyTags).') – Vertrauenskette gebrochen.');
        } elseif (count($matching) < count($ds)) {
            $out->info('Nicht jeder DS-Record hat einen passenden DNSKEY (z. B. während eines Schlüsselwechsels).');
        }

        // Ablauf der Signaturen (RRSIG über SOA und DNSKEY)
        $this->checkSignatures($out, [...$soa->ofType('RRSIG'), ...$ctx->dns->query($name, 'DNSKEY', true)->ofType('RRSIG')], $this->onCloudflare($ctx));

        $tags = array_map(fn ($rr) => $rr->keytag, $ds);
        sort($tags);

        return $out->state(['status' => $soa->authenticated ? 'secure' : 'unvalidated', 'ds' => $tags]);
    }

    /** Cloudflare signiert live mit kurzer Laufzeit – dort ist eine nahe Ablaufzeit normal. */
    private function onCloudflare(CheckContext $ctx): bool
    {
        try {
            $ns = $ctx->dns->ns($ctx->name());
        } catch (DnsException) {
            return false;
        }

        return $ns !== [] && array_filter($ns, fn ($host) => ! Cloudflare::isNameserver($host)) === [];
    }

    /** @param  list<RR>  $sigs */
    private function checkSignatures(CheckOutcome $out, array $sigs, bool $liveSigning = false): void
    {
        $earliest = null;
        foreach ($sigs as $sig) {
            $exp = Dnssec::time((string) $sig->sigexp);
            if ($exp !== null && ($earliest === null || $exp->lt($earliest[0]))) {
                $earliest = [$exp, (string) $sig->typecovered];
            }
        }
        if ($earliest === null) {
            return;
        }

        [$exp, $type] = $earliest;
        $hours = now()->diffInHours($exp, false);
        $out->data('Signaturen (RRSIG) gültig bis', $exp->timezone(config('app.timezone'))->format('d.m.Y H:i')." ({$type})");

        if ($hours < 0) {
            $out->error("RRSIG über {$type} ist abgelaufen – validierende Resolver lehnen die Zone ab.");
        } elseif (! $liveSigning && $hours < self::EXPIRY_WARNING_HOURS) {
            $minutes = max(1, (int) now()->diffInMinutes($exp, false));
            $out->warning("RRSIG über {$type} läuft in ".($minutes >= 60 ? intdiv($minutes, 60).' Stunde(n)' : "{$minutes} Minute(n)").' ab – wird die Zone nicht neu signiert, fällt sie aus.');
        }
    }
}
