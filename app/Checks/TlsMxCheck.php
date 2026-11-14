<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Support\Tls\StartTlsUnsupported;
use App\Support\Tls\TlsInspector;
use RuntimeException;

/** STARTTLS-Zertifikate der MX-Hosts (Port 25 muss ausgehend erlaubt sein). */
class TlsMxCheck implements Check
{
    public function __construct(private readonly TlsInspector $tls) {}

    public function type(): CheckType
    {
        return CheckType::TlsMx;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $hosts = $ctx->mxHosts();
        if ($hosts === []) {
            return $out->info('Keine MX-Hosts.')->state([]);
        }

        $state = [];
        $unreachable = 0;
        foreach ($hosts as $host) {
            try {
                $cert = $this->tls->starttls($host);
            } catch (StartTlsUnsupported $e) {
                $out->error("{$host}: kein STARTTLS – Mails werden unverschlüsselt übertragen.");
                $state[$host] = 'no-starttls';

                continue;
            } catch (RuntimeException $e) {
                $unreachable++;
                $out->add(CheckStatus::Unknown, "{$host}: {$e->getMessage()}");
                $state[$host] = 'unreachable';

                continue;
            }

            // Ohne MTA-STS/DANE ist ein unpassender Name beim MX nur eine Warnung
            TlsWebCheck::evaluate($out, $host, $cert, $ctx, nameMismatchIsWarning: true);
            $minDays = min($minDays ?? PHP_INT_MAX, $cert->daysLeft());
            $state[$host] = ['issuer' => $cert->issuer, 'trusted' => $cert->trusted];
        }

        if ($unreachable === count($hosts)) {
            $out->summary('Kein MX-Host auf Port 25 erreichbar – ausgehender Port 25 evtl. vom Hoster gesperrt.');
        }

        if (! $out->status()->isProblem() && $unreachable === 0 && isset($minDays)) {
            $out->summary(count($hosts)." MX-Host(s) mit STARTTLS, nächster Zertifikatsablauf in {$minDays} Tagen");
        }

        return $out->state($state);
    }
}
