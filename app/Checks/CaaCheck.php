<?php

namespace App\Checks;

use App\Enums\CheckType;

/**
 * CAA (RFC 8659): welche Zertifizierungsstellen Zertifikate ausstellen dürfen.
 * Gesucht wird wie bei den CAs: erst an der Domain, dann an den übergeordneten Namen (ohne TLD).
 */
class CaaCheck implements Check
{
    private const KNOWN_TAGS = ['issue', 'issuewild', 'iodef', 'issuemail', 'issuevmc', 'contactemail', 'contactphone'];

    public function type(): CheckType
    {
        return CheckType::Caa;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $labels = explode('.', $ctx->name());
        $records = [];
        $foundAt = null;

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $name = implode('.', array_slice($labels, $i));
            $rrs = $ctx->dns->query($name, 'CAA')->ofType('CAA');
            if ($rrs !== []) {
                $foundAt = $name;
                foreach ($rrs as $rr) {
                    $records[] = [
                        'flags' => (int) $rr->flags,
                        'tag' => strtolower(trim((string) $rr->tag)),
                        'value' => trim((string) $rr->value->value()),
                    ];
                }
                break;
            }
        }

        if ($records === []) {
            return $out->info('Kein CAA-Record – jede Zertifizierungsstelle darf Zertifikate ausstellen (optional).')->state(['records' => []]);
        }

        $lines = array_map(fn ($r) => "{$r['flags']} {$r['tag']} \"{$r['value']}\"", $records);
        $out->data('CAA'.($foundAt !== $ctx->name() ? " (von {$foundAt})" : ''), $lines);
        if ($foundAt !== $ctx->name()) {
            $out->data('Geerbt von', $foundAt);
        }

        $issue = $this->cas($records, 'issue');
        $wild = $this->cas($records, 'issuewild');

        foreach ($records as $r) {
            if (! in_array($r['tag'], self::KNOWN_TAGS, true) && ($r['flags'] & 128)) {
                $out->error("Unbekanntes kritisches Tag „{$r['tag']}“ – Zertifizierungsstellen dürfen dann gar nicht ausstellen.");
            }
            if ($r['tag'] === 'iodef' && ! preg_match('/^(mailto:|https?:\/\/)/i', $r['value'])) {
                $out->warning("Ungültiges iodef-Ziel „{$r['value']}“ (mailto: oder https: erwartet).");
            }
        }

        if ($issue === null) {
            $out->info('Kein issue-Tag – die Einschränkung gilt nur für '.($wild !== null ? 'Wildcard-Zertifikate.' : 'andere Zwecke (z. B. iodef).'));
        } elseif ($issue === []) {
            $out->warning('CAA verbietet allen Zertifizierungsstellen die Ausstellung (issue ";") – Zertifikate können nicht neu ausgestellt werden.');
        } else {
            $out->ok('Erlaubte Zertifizierungsstellen: '.implode(', ', $issue).'.');
        }

        if ($wild !== null) {
            $out->data('Wildcard-Zertifikate', $wild === [] ? 'nicht erlaubt' : $wild);
        }

        if (! $out->status()->isProblem() && $issue) {
            $out->summary('CAA erlaubt: '.implode(', ', $issue));
        }

        sort($lines);

        return $out->state(['records' => $lines, 'from' => $foundAt]);
    }

    /** CA-Domains eines Tags; [] = keine CA erlaubt, null = Tag nicht vorhanden. @return list<string>|null */
    private function cas(array $records, string $tag): ?array
    {
        $values = array_column(array_filter($records, fn ($r) => $r['tag'] === $tag), 'value');
        if ($values === []) {
            return null;
        }
        $cas = array_map(fn ($v) => strtolower(trim(explode(';', $v)[0])), $values);

        return array_values(array_unique(array_filter($cas)));
    }
}
