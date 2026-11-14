<?php

namespace App\Checks;

use App\Enums\CheckType;
use Illuminate\Support\Facades\Http;
use Throwable;

class BimiCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Bimi;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $records = array_values(array_filter($ctx->dns->txt('default._bimi.'.$ctx->name()), fn ($t) => preg_match('/^v=BIMI1\s*(;|$)/i', trim($t))));

        if ($records === []) {
            return $out->info('Kein BIMI eingerichtet (optional).')->state(['record' => null]);
        }

        $tags = Tags::parse($records[0]);
        $logo = $tags['l'] ?? '';
        $vmc = $tags['a'] ?? '';
        $out->data('Record', $records[0]);

        // BIMI setzt DMARC mit Durchsetzung voraus
        $dmarc = $ctx->dmarc();
        $p = strtolower($dmarc['p'] ?? 'none');
        $pct = (int) ($dmarc['pct'] ?? 100);
        if (! in_array($p, ['quarantine', 'reject'], true) || $pct < 100) {
            $out->error('BIMI erfordert DMARC p=quarantine oder p=reject mit pct=100.');
        }

        if ($logo === '') {
            $out->info('Kein Logo (l=) angegeben – BIMI-Opt-out.');
        } elseif (! str_starts_with(strtolower($logo), 'https://')) {
            $out->error('Logo-URL muss HTTPS verwenden.');
        } else {
            try {
                $res = Http::timeout(10)->get($logo);
                $body = $res->body();
                if (! $res->successful()) {
                    $out->error("Logo nicht abrufbar (HTTP {$res->status()}).");
                } elseif (! str_contains(substr($body, 0, 2000), '<svg')) {
                    $out->error('Logo ist keine SVG-Datei.');
                } else {
                    $out->ok('Logo abrufbar ('.round(strlen($body) / 1024, 1).' KB).');
                    if (! preg_match('/baseProfile="tiny-ps"/i', $body)) {
                        $out->warning('SVG ist nicht im Profil „SVG Tiny PS“ – wird von Gmail/Apple abgelehnt.');
                    }
                    if (strlen($body) > 32 * 1024) {
                        $out->warning('SVG ist größer als 32 KB.');
                    }
                }
            } catch (Throwable $e) {
                $out->error('Logo nicht abrufbar: '.mb_strimwidth($e->getMessage(), 0, 150, '…'));
            }
        }

        $vmc === ''
            ? $out->info('Kein VMC/CMC-Zertifikat (a=) – Gmail und Apple zeigen das Logo dann nicht an.')
            : $out->ok('Zertifikat (a=) hinterlegt.');

        $out->data('Logo (l)', $logo ?: '–')->data('Zertifikat (a)', $vmc ?: '–');

        return $out->state(['record' => trim($records[0])]);
    }
}
