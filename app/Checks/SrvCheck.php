<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\SrvService;
use App\Support\Dns\DnsException;

/**
 * SRV-Records (RFC 2782) der bekannten Dienste aus der SrvService-Registry und der
 * je Domain erwarteten Dienste. Ein fehlender bekannter Dienst ist kein Befund; geprüft
 * werden veröffentlichte Records (Ziel auflösbar, kein CNAME, Port, „.“-Ziel) und ob
 * erwartete Dienste vorhanden sind. Zu jedem Problem gibt es einen konkreten Vorschlag.
 */
class SrvCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Srv;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make()->infoIsNeutral();
        $domain = $ctx->name();
        $expected = $this->expected($ctx);
        $services = array_values(array_unique([...array_column(SrvService::cases(), 'value'), ...$expected]));

        $lines = [];
        $suggestions = [];
        $missing = [];

        foreach ($services as $service) {
            $name = "{$service}.{$domain}";
            $label = SrvService::labelFor($service);
            $known = SrvService::tryFrom($service);

            try {
                $records = $ctx->dns->srv($name);
            } catch (DnsException $e) {
                $out->add(CheckStatus::Unknown, "{$label}: SRV-Abfrage nicht möglich ({$e->getMessage()}).");

                continue;
            }

            if ($records === []) {
                if (in_array($service, $expected, true)) {
                    $missing[] = $service;
                    $out->warning("{$label}: erwarteter SRV-Record {$name} fehlt.");
                    $suggestions[] = "{$name}. 3600 IN SRV 0 1 ".($known?->port() ?? '<port>').' <host>.'
                        .' – Record anlegen (Host und Port des Dienstes eintragen) oder den Dienst aus „Erwartete SRV-Dienste“ entfernen.';
                }

                continue;
            }

            foreach ($records as $r) {
                $lines[] = "{$service}: {$r['priority']} {$r['weight']} {$r['port']} ".($r['target'] === '' ? '.' : $r['target']);
            }

            $null = array_filter($records, fn ($r) => $r['target'] === '');
            if ($null !== []) {
                if (count($records) > 1) {
                    $out->error("{$label}: Ziel „.“ (nicht angeboten) steht neben weiteren Records – das ist widersprüchlich.");
                    $suggestions[] = "{$name}: den Record mit Ziel „.“ löschen, wenn der Dienst angeboten wird, sonst die übrigen Records.";
                } elseif (in_array($service, $expected, true)) {
                    $out->warning("{$label}: als erwartet eingetragen, aber per Ziel „.“ als nicht angeboten markiert.");
                    $suggestions[] = "{$name}: Ziel „.“ durch Host und Port des Dienstes ersetzen oder den Dienst aus „Erwartete SRV-Dienste“ entfernen.";
                } else {
                    $out->info("{$label}: Dienst wird ausdrücklich nicht angeboten (Ziel „.“).");
                }

                continue;
            }

            foreach ($records as $r) {
                $this->checkRecord($ctx, $out, $suggestions, $name, $label, $known, $r);
            }
        }

        if ($lines === [] && $expected === []) {
            $out->info('Keine SRV-Records für bekannte Dienste veröffentlicht (optional, nur nötig für Dienste wie Autodiscover, IMAP/Submission, CalDAV oder XMPP).');
        }

        $out->data('SRV-Records', $lines)
            ->data('Erwartete Dienste', $expected === [] ? '–' : $expected);
        if ($suggestions !== []) {
            $out->data('Vorschläge', array_values(array_unique($suggestions)));
        }

        if (! $out->status()->isProblem() && $lines !== []) {
            $count = count(array_unique(array_map(fn ($l) => strstr($l, ':', true), $lines)));
            $out->summary("{$count} SRV-Dienst(e) veröffentlicht, alle Ziele auflösbar");
        }

        sort($lines);

        return $out->state(['records' => $lines, 'missing' => $missing]);
    }

    /** @param  array{priority:int, weight:int, port:int, target:string, ttl:int}  $r */
    private function checkRecord(CheckContext $ctx, CheckOutcome $out, array &$suggestions, string $name, string $label, ?SrvService $known, array $r): void
    {
        $target = $r['target'];
        $where = "{$target}:{$r['port']}";
        $fix = "{$name}. {$r['ttl']} IN SRV {$r['priority']} {$r['weight']}";
        $problem = false;

        if ($r['port'] === 0) {
            $problem = true;
            $out->error("{$label}: Port 0 bei Ziel {$target} – Clients können den Dienst nicht erreichen.");
            $suggestions[] = "{$fix} ".($known?->port() ?? '<port>')." {$target}. – richtigen Port eintragen.";
        }

        $alias = $ctx->dns->cname($target);
        if ($alias !== null) {
            $problem = true;
            $out->warning("{$label}: Ziel {$target} ist ein CNAME auf {$alias} – laut RFC 2782 muss das Ziel ein Hostname mit A/AAAA sein, manche Clients ignorieren den Record.");
            $suggestions[] = "{$fix} {$r['port']} {$alias}. – Ziel direkt auf {$alias} setzen.";
        }

        try {
            $ips = $ctx->dns->ips($target);
        } catch (DnsException $e) {
            $out->add(CheckStatus::Unknown, "{$label}: Adressen des Ziels {$target} nicht prüfbar ({$e->getMessage()}).");

            return;
        }

        if ($ips === []) {
            $problem = true;
            $out->error("{$label}: Ziel {$target} hat keine A/AAAA-Adresse – der Dienst ist über SRV nicht erreichbar.");
            $suggestions[] = "{$target}: A/AAAA-Record anlegen oder {$name} auf einen existierenden Host ändern bzw. löschen, wenn der Dienst nicht mehr angeboten wird.";
        }

        if (str_ends_with($target, '.online.lync.com')) {
            $out->info("{$label}: zeigt auf Skype for Business Online ({$target}), das Microsoft abgeschaltet hat; für reine Teams-Umgebungen wird der Record in der Regel nicht mehr benötigt.");
        }

        if ($known !== null && $r['port'] !== 0 && $r['port'] !== $known->port()) {
            $out->info("{$label}: Port {$r['port']} statt des üblichen Ports {$known->port()}.");
        }

        if (! $problem) {
            $out->ok("{$label}: {$where} (Priorität {$r['priority']}, Gewicht {$r['weight']}) → ".implode(', ', $ips));
        }
    }

    /** Je Domain erwartete Dienste, normalisiert auf „_dienst._protokoll“. @return list<string> */
    private function expected(CheckContext $ctx): array
    {
        $list = array_map(fn ($s) => strtolower(trim((string) $s, " .\t")), $ctx->domain->srv_services ?? []);

        return array_values(array_unique(array_filter($list, fn ($s) => preg_match(SrvService::NAME_PATTERN, $s))));
    }
}
