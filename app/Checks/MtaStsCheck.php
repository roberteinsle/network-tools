<?php

namespace App\Checks;

use App\Enums\CheckType;
use Illuminate\Support\Facades\Http;
use Throwable;

/** MTA-STS (RFC 8461): TXT-Record, Policy-Datei per HTTPS und Abgleich mit den MX-Hosts. */
class MtaStsCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::MtaSts;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $records = array_values(array_filter($ctx->dns->txt('_mta-sts.'.$ctx->name()), fn ($t) => preg_match('/^v=STSv1\s*;/i', trim($t))));

        if ($records === []) {
            return $out->info('Kein MTA-STS eingerichtet (optional, schützt eingehende Mails vor TLS-Downgrade).')->state(['record' => null]);
        }
        if (count($records) > 1) {
            $out->error('Mehrere _mta-sts-Records – MTA-STS wird ignoriert.');
        }

        $tags = Tags::parse($records[0]);
        $out->data('TXT', $records[0]);
        if (empty($tags['id'])) {
            $out->error('TXT-Record ohne id=.');
        }

        $url = 'https://mta-sts.'.$ctx->name().'/.well-known/mta-sts.txt';
        try {
            $response = Http::timeout(10)->withoutRedirecting()->get($url);
        } catch (Throwable $e) {
            return $out->error('Policy nicht abrufbar: '.$this->shortError($e))
                ->data('Policy-URL', $url)
                ->state(['id' => $tags['id'] ?? null, 'policy' => 'unreachable']);
        }

        if (! $response->successful()) {
            return $out->error("Policy liefert HTTP {$response->status()} (Weiterleitungen sind nicht erlaubt).")
                ->data('Policy-URL', $url)
                ->state(['id' => $tags['id'] ?? null, 'policy' => 'http-'.$response->status()]);
        }

        $policy = $this->parsePolicy($response->body());
        $mode = $policy['mode'] ?? null;
        $patterns = $policy['mx'] ?? [];
        $maxAge = (int) ($policy['max_age'] ?? 0);

        if (($policy['version'] ?? null) !== 'STSv1') {
            $out->error('Policy ohne „version: STSv1“.');
        }
        if (! str_starts_with(strtolower($response->header('Content-Type')), 'text/plain')) {
            $out->warning('Policy wird nicht als text/plain ausgeliefert.');
        }

        match ($mode) {
            'enforce' => $out->ok('Modus enforce.'),
            'testing' => $out->info('Modus testing – Fehler werden nur berichtet, nicht erzwungen.'),
            'none' => $out->warning('Modus none – MTA-STS ist deaktiviert.'),
            default => $out->error('Ungültiger oder fehlender mode.'),
        };

        if ($maxAge < 86400) {
            $out->warning("max_age={$maxAge} ist sehr kurz (empfohlen ≥ 604800).");
        }

        if ($patterns === []) {
            $out->error('Policy enthält keine mx-Einträge.');
        } elseif ($mode !== 'none') {
            foreach ($ctx->mxHosts() as $host) {
                if (! $this->matches($host, $patterns)) {
                    $mode === 'enforce'
                        ? $out->error("MX {$host} ist nicht in der Policy – Mails an diesen Host werden abgelehnt.")
                        : $out->warning("MX {$host} ist nicht in der Policy.");
                }
            }
        }

        $out->data('Policy-URL', $url)
            ->data('mode', $mode ?? '–')
            ->data('mx', $patterns)
            ->data('max_age', (string) $maxAge);

        if (! $out->status()->isProblem()) {
            $out->summary("MTA-STS {$mode}, ".count($patterns).' MX-Muster');
        }

        return $out->state(['id' => $tags['id'] ?? null, 'mode' => $mode, 'mx' => $patterns]);
    }

    private function parsePolicy(string $body): array
    {
        $policy = ['mx' => []];
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            $k === 'mx' ? $policy['mx'][] = strtolower($v) : $policy[$k] = $k === 'mode' ? strtolower($v) : $v;
        }

        return $policy;
    }

    private function matches(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = rtrim($pattern, '.');
            if ($pattern === $host) {
                return true;
            }
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                $label = substr($host, 0, -strlen($suffix));
                if (str_ends_with($host, $suffix) && $label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function shortError(Throwable $e): string
    {
        $msg = preg_replace('/\s+/', ' ', $e->getMessage());
        $msg = preg_replace('/\s*\(see https:\/\/curl\.se[^)]*\)/', '', $msg);
        $msg = preg_replace('/\s+for https?:\/\/\S+$/', '', $msg);
        $msg = preg_replace('/^cURL error \d+: /', '', $msg);

        return mb_strimwidth($msg, 0, 200, '…');
    }
}
