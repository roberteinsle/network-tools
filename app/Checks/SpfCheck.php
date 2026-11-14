<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Support\Dns\DnsException;
use App\Support\Dns\DnsResolver;

/**
 * SPF nach RFC 7208 inkl. Zähler für das 10-DNS-Lookup-Limit (include, a, mx, ptr,
 * exists, redirect – rekursiv) und das Limit von 2 „void lookups“.
 */
class SpfCheck implements Check
{
    public const LOOKUP_LIMIT = 10;

    public const VOID_LIMIT = 2;

    private DnsResolver $dns;

    private int $lookups = 0;

    private int $voids = 0;

    /** @var list<string> */
    private array $tree = [];

    /** @var list<array{0:string,1:string}> [status, text] */
    private array $problems = [];

    private array $visited = [];

    public function type(): CheckType
    {
        return CheckType::Spf;
    }

    public static function records(DnsResolver $dns, string $domain): array
    {
        return array_values(array_filter($dns->txt($domain), fn ($t) => preg_match('/^v=spf1(\s|$)/i', trim($t))));
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $this->dns = $ctx->dns;
        $this->lookups = $this->voids = 0;
        $this->tree = $this->problems = $this->visited = [];

        $out = CheckOutcome::make();
        $records = self::records($ctx->dns, $ctx->name());

        if ($records === []) {
            $receivesNoMail = $ctx->hasNullMx() || $ctx->mx() === [];

            return $out->add(
                $receivesNoMail ? CheckStatus::Warning : CheckStatus::Error,
                $receivesNoMail
                    ? 'Kein SPF-Record. Für Domains ohne Mailversand empfohlen: „v=spf1 -all“.'
                    : 'Kein SPF-Record vorhanden.'
            )->state(['record' => null]);
        }

        if (count($records) > 1) {
            $out->error('Mehrere SPF-Records ('.count($records).') – das ergibt PermError, SPF schlägt komplett fehl.');
        }

        $record = trim($records[0]);
        $this->visited[$ctx->name()] = true;
        $all = $this->evaluate($record, $ctx->name(), 0, true);

        foreach ($this->problems as [$status, $text]) {
            $out->add(CheckStatus::from($status), $text);
        }

        // all-Mechanismus der obersten Ebene
        match ($all) {
            '+all' => $out->error('„+all“ erlaubt jedem Server, im Namen der Domain zu senden.'),
            '?all' => $out->warning('„?all“ (neutral) bietet keinen Schutz. Empfohlen: „~all“ oder „-all“.'),
            '~all' => $out->ok('Abschluss „~all“ (Softfail).'),
            '-all' => $out->ok('Abschluss „-all“ (Fail).'),
            null => $out->warning('Kein „all“-Mechanismus und kein redirect – Standard ist neutral.'),
            default => null,
        };

        if ($this->lookups > self::LOOKUP_LIMIT) {
            $out->error("DNS-Lookup-Limit überschritten: {$this->lookups} von ".self::LOOKUP_LIMIT.' – PermError, SPF schlägt fehl.');
        } elseif ($this->lookups >= 9) {
            $out->warning("Fast am DNS-Lookup-Limit: {$this->lookups} von ".self::LOOKUP_LIMIT.'.');
        } else {
            $out->ok("DNS-Lookups: {$this->lookups} von ".self::LOOKUP_LIMIT.'.');
        }

        if ($this->voids > self::VOID_LIMIT) {
            $out->error("{$this->voids} Void-Lookups (Limit ".self::VOID_LIMIT.') – PermError bei vielen Empfängern.');
        } elseif ($this->voids > 0) {
            $out->warning("{$this->voids} Void-Lookup(s) (Abfragen ohne Ergebnis).");
        }

        if (strlen($record) > 450) {
            $out->info('SPF-Record ist sehr lang ('.strlen($record).' Zeichen) – DNS-Antwort kann über 512 Byte gehen.');
        }

        $out->data('Record', $record)
            ->data('DNS-Lookups', "{$this->lookups} / ".self::LOOKUP_LIMIT)
            ->data('Void-Lookups', "{$this->voids} / ".self::VOID_LIMIT)
            ->data('Auflösung', $this->tree);

        if (! $out->status()->isProblem()) {
            $out->summary("SPF gültig, {$this->lookups}/".self::LOOKUP_LIMIT.' Lookups, '.($all ?? 'kein all'));
        }

        return $out->state(['record' => $record, 'lookups' => $this->lookups]);
    }

    /** Wertet einen SPF-Record aus und gibt den all-Mechanismus (mit Qualifier) zurück. */
    private function evaluate(string $record, string $domain, int $depth, bool $top = false): ?string
    {
        $indent = str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth); // geschützte Leerzeichen bleiben in der Anzeige erhalten
        if ($top) {
            $this->tree[] = $domain;
        }

        $terms = preg_split('/\s+/', trim($record));
        array_shift($terms); // v=spf1
        $all = null;
        $redirect = null;

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $qualifier = in_array($term[0], ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $mech = ltrim($term, '+-~?');
            [$name, $arg] = array_pad(preg_split('/[:=]/', $mech, 2), 2, null);
            $name = strtolower(preg_replace('#/.*$#', '', (string) $name));
            $arg = $arg !== null ? preg_replace('#/\d+(//\d+)?$#', '', $arg) : null; // CIDR entfernen
            $hasMacro = $arg !== null && str_contains($arg, '%{');

            switch ($name) {
                case 'all':
                    $all = $qualifier.'all';
                    break;

                case 'ip4':
                case 'ip6':
                    break;

                case 'include':
                    $this->lookups++;
                    $this->tree[] = "{$indent}├─ include:{$arg}  [Lookup {$this->lookups}]";
                    if ($hasMacro) {
                        $this->problems[] = ['info', "include:{$arg} enthält Makros – nicht weiter aufgelöst."];
                        break;
                    }
                    $this->descend((string) $arg, $depth + 1, 'include');
                    break;

                case 'a':
                case 'mx':
                    $this->lookups++;
                    $target = $arg ?: $domain;
                    $this->tree[] = "{$indent}├─ {$name}".($arg ? ":{$arg}" : '')."  [Lookup {$this->lookups}]";
                    if ($hasMacro) {
                        break;
                    }
                    try {
                        if ($name === 'a') {
                            if ($this->dns->ips($target) === []) {
                                $this->voids++;
                            }
                        } else {
                            $mx = $this->dns->mx($target);
                            if ($mx === []) {
                                $this->voids++;
                            } elseif (count($mx) > 10) {
                                $this->problems[] = ['error', "mx:{$target} hat mehr als 10 MX-Records – PermError."];
                            }
                        }
                    } catch (DnsException $e) {
                        $this->problems[] = ['warning', $e->getMessage()];
                    }
                    break;

                case 'ptr':
                    $this->lookups++;
                    $this->tree[] = "{$indent}├─ ptr  [Lookup {$this->lookups}]";
                    $this->problems[] = ['warning', '„ptr“ ist veraltet (RFC 7208) und sollte nicht verwendet werden.'];
                    break;

                case 'exists':
                    $this->lookups++;
                    $this->tree[] = "{$indent}├─ exists:{$arg}  [Lookup {$this->lookups}]";
                    break;

                case 'redirect':
                    $redirect = $arg;
                    break;

                case 'exp':
                    break;

                default:
                    if (! str_contains($mech, '=')) {
                        $this->problems[] = ['error', "Unbekannter Mechanismus „{$term}“ in {$domain} – PermError."];
                    }
            }
        }

        // redirect wird nur ausgewertet, wenn kein all vorhanden ist
        if ($redirect !== null && $all === null) {
            $this->lookups++;
            $this->tree[] = "{$indent}└─ redirect={$redirect}  [Lookup {$this->lookups}]";
            if (! str_contains($redirect, '%{')) {
                return $this->descend($redirect, $depth + 1, 'redirect');
            }
        }

        return $all;
    }

    private function descend(string $target, int $depth, string $kind): ?string
    {
        $target = strtolower(rtrim($target, '.'));
        if (isset($this->visited[$target])) {
            $this->problems[] = ['error', "Schleife: {$target} wird mehrfach eingebunden."];

            return null;
        }
        if ($depth > 10) {
            return null;
        }
        $this->visited[$target] = true;

        try {
            $records = self::records($this->dns, $target);
        } catch (DnsException $e) {
            $this->problems[] = ['warning', "{$kind}:{$target} nicht abfragbar – {$e->getMessage()}"];

            return null;
        }

        if ($records === []) {
            $this->voids++;
            $this->problems[] = ['error', "{$kind}:{$target} hat keinen SPF-Record – PermError."];

            return null;
        }
        if (count($records) > 1) {
            $this->problems[] = ['error', "{$target} hat mehrere SPF-Records – PermError."];
        }

        return $this->evaluate($records[0], $target, $depth);
    }
}
