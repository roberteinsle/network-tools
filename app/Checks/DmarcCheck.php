<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Dns\DnsException;

class DmarcCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Dmarc;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $name = '_dmarc.'.$ctx->name();
        $records = array_values(array_filter($ctx->dns->txt($name), fn ($t) => preg_match('/^v=DMARC1\s*(;|$)/i', trim($t))));

        if ($records === []) {
            return $out->error('Kein DMARC-Record unter '.$name.'.')->state(['record' => null]);
        }
        if (count($records) > 1) {
            return $out->error('Mehrere DMARC-Records – Empfänger ignorieren DMARC dann komplett.')
                ->data('Records', $records)->state(['record' => $records]);
        }

        $record = trim($records[0]);
        $tags = Tags::parse($record);
        $p = strtolower($tags['p'] ?? '');
        $sp = strtolower($tags['sp'] ?? $p);
        $pct = (int) ($tags['pct'] ?? 100);

        match ($p) {
            'reject' => $out->ok('Richtlinie p=reject.'),
            'quarantine' => $out->ok('Richtlinie p=quarantine.'),
            'none' => $out->warning('Richtlinie p=none – nur Monitoring, kein Schutz vor Spoofing.'),
            '' => $out->error('Pflicht-Tag p= fehlt.'),
            default => $out->error("Ungültige Richtlinie p={$p}."),
        };

        if (isset($tags['sp']) && $sp === 'none' && $p !== 'none') {
            $out->warning('Subdomain-Richtlinie sp=none – Subdomains sind ungeschützt.');
        }
        if ($pct < 100) {
            $out->warning("pct={$pct} – Richtlinie gilt nur für {$pct} % der Mails.");
        }

        if (empty($tags['rua'])) {
            $out->warning('Keine Aggregate-Reports (rua=) – Auswertung von Fehlzustellungen nicht möglich.');
        } else {
            foreach ($this->externalReportDomains($tags['rua'], $ctx->name()) as $reportDomain) {
                $auth = "{$ctx->name()}._report._dmarc.{$reportDomain}";
                try {
                    $ok = collect($ctx->dns->txt($auth))->contains(fn ($t) => stripos($t, 'v=DMARC1') === 0);
                } catch (DnsException) {
                    $ok = true; // im Zweifel nicht alarmieren
                }
                $ok
                    ? $out->ok("Externer Report-Empfänger {$reportDomain} ist autorisiert.")
                    : $out->warning("Externer Report-Empfänger {$reportDomain} hat die Domain nicht autorisiert ({$auth} fehlt) – Reports kommen nicht an.");
            }
        }

        foreach (['adkim', 'aspf'] as $align) {
            if (isset($tags[$align]) && ! in_array(strtolower($tags[$align]), ['r', 's'], true)) {
                $out->error("Ungültiger Wert {$align}={$tags[$align]}.");
            }
        }

        $out->data('Record', $record)
            ->data('Richtlinie (p)', $p ?: '–')
            ->data('Subdomains (sp)', $sp ?: '–')
            ->data('pct', (string) $pct)
            ->data('Aggregate-Reports (rua)', $tags['rua'] ?? '–')
            ->data('Forensik-Reports (ruf)', $tags['ruf'] ?? '–')
            ->data('Alignment DKIM/SPF', ($tags['adkim'] ?? 'r').' / '.($tags['aspf'] ?? 'r'));

        if (! $out->status()->isProblem()) {
            $out->summary("DMARC p={$p}".($pct < 100 ? " pct={$pct}" : '').(isset($tags['rua']) ? ', Reports aktiv' : ''));
        }

        return $out->state(['record' => $record]);
    }

    /** @return list<string> Report-Domains, die nicht zur eigenen Organisation gehören */
    private function externalReportDomains(string $rua, string $domain): array
    {
        $result = [];
        foreach (explode(',', $rua) as $uri) {
            if (! preg_match('/^mailto:[^@]+@([^!\s]+)/i', trim($uri), $m)) {
                continue;
            }
            $host = strtolower(rtrim($m[1], '.'));
            if ($host === $domain || str_ends_with($host, '.'.$domain) || str_ends_with($domain, '.'.$host)) {
                continue;
            }
            $result[] = $host;
        }

        return array_values(array_unique($result));
    }
}
