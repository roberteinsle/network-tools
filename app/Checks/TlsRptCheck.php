<?php

namespace App\Checks;

use App\Enums\CheckType;

class TlsRptCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::TlsRpt;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $records = array_values(array_filter($ctx->dns->txt('_smtp._tls.'.$ctx->name()), fn ($t) => preg_match('/^v=TLSRPTv1\s*;/i', trim($t))));
        $hasMtaSts = $ctx->dns->txt('_mta-sts.'.$ctx->name()) !== [];

        if ($records === [] && ! $ctx->receivesMail()) {
            return $out->notApplicable('Domain empfängt keine Mail (kein MX bzw. Null-MX) – TLS-RPT nicht relevant.')->state(['record' => null]);
        }
        if ($records === []) {
            return ($hasMtaSts
                ? $out->warning('Kein TLS-RPT, obwohl MTA-STS aktiv ist – Zustellprobleme bleiben unbemerkt.')
                : $out->info('Kein TLS-RPT eingerichtet (optional).'))
                ->state(['record' => null]);
        }
        if (count($records) > 1) {
            $out->error('Mehrere TLS-RPT-Records.');
        }

        $tags = Tags::parse($records[0]);
        $rua = $tags['rua'] ?? '';
        if ($rua === '') {
            $out->error('TLS-RPT ohne rua=.');
        } else {
            foreach (explode(',', $rua) as $uri) {
                preg_match('/^(mailto:[^@\s]+@[^\s]+|https:\/\/\S+)$/i', trim($uri))
                    ? $out->ok('Report-Ziel '.trim($uri))
                    : $out->error('Ungültiges Report-Ziel „'.trim($uri).'“ (mailto: oder https: erwartet).');
            }
        }

        return $out->data('Record', $records[0])
            ->state(['record' => trim($records[0])]);
    }
}
