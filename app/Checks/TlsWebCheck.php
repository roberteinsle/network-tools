<?php

namespace App\Checks;

use App\Enums\CheckType;
use App\Support\Dns\DnsException;
use App\Support\Tls\CertificateInfo;
use App\Support\Tls\TlsInspector;
use RuntimeException;

class TlsWebCheck implements Check
{
    public function __construct(private readonly TlsInspector $tls) {}

    public function type(): CheckType
    {
        return CheckType::TlsWeb;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $hosts = $ctx->domain->webHosts();

        // MTA-STS-Host mitprüfen, wenn MTA-STS eingerichtet ist
        if ($ctx->dns->txt('_mta-sts.'.$ctx->name()) !== []) {
            $hosts[] = 'mta-sts.'.$ctx->name();
        }

        $state = [];
        $checked = 0;
        foreach (array_unique($hosts) as $host) {
            try {
                if ($ctx->dns->ips($host) === []) {
                    continue; // Host ohne Adresse: nichts zu prüfen
                }
            } catch (DnsException) {
                continue;
            }

            $checked++;
            try {
                $cert = $this->tls->https($host);
            } catch (RuntimeException $e) {
                $out->error("{$host}: HTTPS nicht erreichbar – {$e->getMessage()}");
                $state[$host] = 'unreachable';

                continue;
            }

            self::evaluate($out, $host, $cert, $ctx);
            $minDays = min($minDays ?? PHP_INT_MAX, $cert->daysLeft());
            $state[$host] = ['issuer' => $cert->issuer, 'trusted' => $cert->trusted, 'names' => $cert->names];
        }

        if ($checked === 0) {
            return $out->info('Keine Web-Hosts mit Adresse – keine Zertifikate zu prüfen.')->state([]);
        }

        if (! $out->status()->isProblem() && isset($minDays)) {
            $out->summary(count($state)." Zertifikat(e) gültig, nächster Ablauf in {$minDays} Tagen");
        }

        return $out->state($state);
    }

    /** Gemeinsame Bewertung für HTTPS- und STARTTLS-Zertifikate. */
    public static function evaluate(CheckOutcome $out, string $host, CertificateInfo $cert, CheckContext $ctx, bool $nameMismatchIsWarning = false): void
    {
        $days = $cert->daysLeft();
        $s = $ctx->settings;

        $out->data($host, [
            'Aussteller: '.$cert->issuer,
            'Namen: '.implode(', ', array_slice($cert->names, 0, 8)).(count($cert->names) > 8 ? ' …' : ''),
            'Gültig bis: '.$cert->validTo->timezone(config('app.timezone'))->format('d.m.Y H:i')." ({$days} Tage)",
            'Protokoll: '.($cert->protocol ?? '–'),
        ]);

        if (! $cert->coversHost($host)) {
            $text = "{$host}: Zertifikat passt nicht zum Hostnamen (".implode(', ', array_slice($cert->names, 0, 3)).').';
            $nameMismatchIsWarning ? $out->warning($text) : $out->error($text);
        } elseif (! $cert->trusted) {
            $out->error("{$host}: Zertifikat nicht vertrauenswürdig – {$cert->verifyError}");
        }

        if ($days < 0) {
            $out->error("{$host}: Zertifikat ist seit ".abs($days).' Tagen abgelaufen.');
        } elseif ($days <= $s->cert_error_days) {
            $out->error("{$host}: Zertifikat läuft in {$days} Tagen ab.");
        } elseif ($days <= $s->cert_warning_days) {
            $out->warning("{$host}: Zertifikat läuft in {$days} Tagen ab.");
        } else {
            $out->ok("{$host}: gültig bis ".$cert->validTo->format('d.m.Y')." ({$days} Tage, {$cert->issuer})");
        }

        if (in_array($cert->protocol, ['TLSv1', 'TLSv1.1'], true)) {
            $out->warning("{$host}: veraltetes Protokoll {$cert->protocol}.");
        }
    }
}
