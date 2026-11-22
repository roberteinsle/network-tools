<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP → HTTPS und HSTS je Web-Host: leitet http:// auf https:// um, und sendet die
 * HTTPS-Antwort des Hosts selbst einen Strict-Transport-Security-Header?
 * Zertifikat und Erreichbarkeit von HTTPS prüft TlsWebCheck.
 */
class HttpCheck implements Check
{
    private const MAX_HOPS = 6;

    /** 1 Jahr: Voraussetzung für die HSTS-Preload-Liste und Empfehlung der Browser-Hersteller. */
    private const HSTS_RECOMMENDED = 31536000;

    public function type(): CheckType
    {
        return CheckType::Http;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $state = [];

        foreach ($ctx->domain->webHosts() as $host) {
            try {
                if ($ctx->dns->ips($host) === []) {
                    continue;
                }
            } catch (DnsException) {
                continue;
            }

            $state[$host] = ['http' => $this->checkRedirect($out, $host), 'hsts' => $this->checkHsts($out, $host)];
        }

        if ($state === []) {
            return $out->info('Keine Web-Hosts mit Adresse – nichts zu prüfen.')->state([]);
        }

        if (! $out->status()->isProblem()) {
            $out->summary(count($state).' Host(s) leiten auf HTTPS um, HSTS aktiv');
        }

        return $out->state($state);
    }

    private function client(): PendingRequest
    {
        return Http::withoutRedirecting()
            ->withoutVerifying() // Zertifikatsfehler meldet TlsWebCheck
            ->timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('Network-Tools (+https://network-tools.einsle.app)');
    }

    private function checkRedirect(CheckOutcome $out, string $host): string
    {
        $url = "http://{$host}/";
        $hops = [];

        try {
            for ($i = 0; $i < self::MAX_HOPS; $i++) {
                $res = $this->client()->get($url);
                $hops[] = $res->status().' '.$url;
                $location = $res->header('Location');
                if (! $res->redirect() || $location === '') {
                    break;
                }
                $url = $this->resolve($url, $location);
                if (str_starts_with($url, 'https://')) {
                    $hops[] = '→ '.$url;
                    break;
                }
            }
        } catch (Throwable $e) {
            if ($hops === []) {
                $out->info("{$host}: Port 80 nicht erreichbar – HTTP wird nicht angeboten.");
                $out->data("{$host} (HTTP)", 'nicht erreichbar');

                return 'closed';
            }
        }

        $out->data("{$host} (HTTP)", $hops);

        if (str_starts_with($url, 'https://')) {
            $out->ok("{$host}: HTTP leitet auf HTTPS um ({$url}).");

            return 'https';
        }

        $status = isset($res) ? $res->status() : 0;
        $out->warning("{$host}: HTTP leitet nicht auf HTTPS um (Status {$status}) – Besucher bleiben unverschlüsselt.");

        return 'no-redirect';
    }

    /** @return array{max_age:int, sub:bool, preload:bool}|string */
    private function checkHsts(CheckOutcome $out, string $host): array|string
    {
        try {
            /** @var Response $res */
            $res = $this->client()->get("https://{$host}/");
        } catch (Throwable) {
            $out->info("{$host}: HTTPS nicht erreichbar – HSTS nicht prüfbar (siehe TLS-Prüfung).");

            return 'unreachable';
        }

        $header = trim($res->header('Strict-Transport-Security'));
        $out->data("{$host} (HSTS)", $header === '' ? 'kein Header' : $header);

        if ($header === '') {
            $out->warning("{$host}: kein HSTS-Header – Browser können auf HTTP herabgestuft werden.");

            return 'missing';
        }

        $maxAge = preg_match('/max-age\s*=\s*"?(\d+)/i', $header, $m) ? (int) $m[1] : 0;
        $sub = (bool) preg_match('/(^|;)\s*includeSubDomains\s*(;|$)/i', $header);
        $preload = (bool) preg_match('/(^|;)\s*preload\s*(;|$)/i', $header);

        if ($maxAge === 0) {
            $out->warning("{$host}: HSTS deaktiviert (max-age=0 oder fehlt).");
        } elseif ($maxAge < self::HSTS_RECOMMENDED) {
            $out->info("{$host}: HSTS max-age={$maxAge} (".intdiv($maxAge, 86400).' Tage) – empfohlen ist mindestens ein Jahr.');
        } else {
            $out->ok("{$host}: HSTS aktiv (".intdiv($maxAge, 86400).' Tage'.($sub ? ', includeSubDomains' : '').($preload ? ', preload' : '').').');
        }

        if ($preload && (! $sub || $maxAge < self::HSTS_RECOMMENDED)) {
            $out->info("{$host}: preload gesetzt, aber für die Preload-Liste sind includeSubDomains und max-age ≥ 1 Jahr nötig.");
        }

        return ['max_age' => $maxAge, 'sub' => $sub, 'preload' => $preload];
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.'/'.$location;
    }
}
