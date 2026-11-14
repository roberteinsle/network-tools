<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DkimProvider;
use App\Support\Dns\DnsException;

/**
 * Prüft die DKIM-Selektoren der für Kunde/Domain gewählten Anbieter (M365, Google …)
 * sowie eigene Selektoren. Pro Anbieter muss mindestens ein gültiger Schlüssel existieren.
 */
class DkimCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Dkim;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        $out = CheckOutcome::make();
        $groups = [];

        foreach ($ctx->domain->effectiveDkimProviders() as $provider) {
            $groups[$provider->getLabel()] = $provider->selectors();
        }
        $custom = array_values(array_filter(array_map('trim', $ctx->domain->dkim_selectors ?? [])));
        if ($custom !== []) {
            $groups['Eigene Selektoren'] = $custom;
        }

        if ($groups === []) {
            return $out->info('Keine DKIM-Selektoren hinterlegt – Anbieter am Kunden oder an der Domain auswählen.')->state([]);
        }

        $state = [];
        $validCount = 0;

        foreach ($groups as $label => $selectors) {
            $validInGroup = 0;
            $found = [];

            foreach ($selectors as $selector) {
                $result = $this->inspect($ctx, $selector);
                $state[$selector] = $result['state'];
                $out->data("{$selector}._domainkey", $result['display']);

                if ($result['valid']) {
                    $validInGroup++;
                    $found[] = $selector;
                    $out->add($result['status'], "{$selector}: {$result['text']}");
                } elseif ($result['exists']) {
                    $out->add($result['status'], "{$selector}: {$result['text']}");
                }
            }

            $validCount += $validInGroup;

            if ($validInGroup === 0) {
                $out->error("{$label}: kein gültiger DKIM-Schlüssel gefunden (".implode(', ', $selectors).').');
            } elseif ($label === DkimProvider::Microsoft365->getLabel() && $validInGroup < 2) {
                $out->info("{$label}: nur ".implode(', ', $found).' aktiv – für die Schlüsselrotation sollten selector1 und selector2 existieren.');
            }
        }

        if (! $out->status()->isProblem()) {
            $out->summary("{$validCount} gültige(r) DKIM-Schlüssel");
        }

        return $out->state($state);
    }

    /** @return array{exists:bool, valid:bool, status:CheckStatus, text:string, display:string, state:mixed} */
    private function inspect(CheckContext $ctx, string $selector): array
    {
        $name = "{$selector}._domainkey.{$ctx->name()}";
        $none = ['exists' => false, 'valid' => false, 'status' => CheckStatus::Info, 'text' => 'nicht vorhanden', 'display' => 'nicht vorhanden', 'state' => null];

        try {
            $cname = $ctx->dns->cname($name);
            $records = array_values(array_filter($ctx->dns->txt($name), fn ($t) => preg_match('/(^|;)\s*(v=DKIM1|p=)/i', $t)));
        } catch (DnsException $e) {
            return ['exists' => true, 'valid' => false, 'status' => CheckStatus::Unknown, 'text' => $e->getMessage(), 'display' => 'Fehler', 'state' => 'error'];
        }

        if ($records === []) {
            if ($cname !== null) {
                return ['exists' => true, 'valid' => false, 'status' => CheckStatus::Info, 'text' => "CNAME auf {$cname}, dort aber kein Schlüssel (bei M365 für den inaktiven Selektor normal).", 'display' => "CNAME → {$cname} (kein Schlüssel)", 'state' => ['cname' => $cname]];
            }

            return $none;
        }

        $tags = Tags::parse($records[0]);
        $p = preg_replace('/\s+/', '', $tags['p'] ?? '');
        $display = ($cname ? "CNAME → {$cname}\n" : '').mb_strimwidth($records[0], 0, 160, '…');
        $state = ['cname' => $cname, 'key' => $p === '' ? 'revoked' : hash('sha256', $p)];

        if ($p === '') {
            return ['exists' => true, 'valid' => false, 'status' => CheckStatus::Warning, 'text' => 'Schlüssel widerrufen (leeres p=).', 'display' => $display, 'state' => $state];
        }

        if (strtolower($tags['k'] ?? 'rsa') === 'ed25519') {
            return ['exists' => true, 'valid' => true, 'status' => CheckStatus::Ok, 'text' => 'Ed25519-Schlüssel.', 'display' => $display, 'state' => $state];
        }

        $bits = $this->rsaBits($p);
        $tlabel = isset($tags['t']) && str_contains($tags['t'], 'y') ? ' Testmodus (t=y) aktiv.' : '';

        [$status, $text] = match (true) {
            $bits === null => [CheckStatus::Error, 'Schlüssel nicht lesbar (ungültiges Base64/DER).'],
            $bits < 1024 => [CheckStatus::Error, "RSA {$bits} Bit – unsicher, wird von Empfängern ignoriert."],
            $bits < 2048 => [CheckStatus::Warning, "RSA {$bits} Bit – empfohlen sind 2048 Bit."],
            default => [CheckStatus::Ok, "RSA {$bits} Bit."],
        };

        if ($tlabel !== '' && $status === CheckStatus::Ok) {
            $status = CheckStatus::Info;
        }

        return ['exists' => true, 'valid' => $bits !== null && $bits >= 1024, 'status' => $status, 'text' => $text.$tlabel, 'display' => $display, 'state' => $state];
    }

    private function rsaBits(string $base64): ?int
    {
        $der = base64_decode($base64, true);
        if ($der === false) {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            // manche Anbieter veröffentlichen PKCS#1 (RSA PUBLIC KEY)
            $pem = str_replace('PUBLIC KEY', 'RSA PUBLIC KEY', $pem);
            $key = @openssl_pkey_get_public($pem);
        }

        return $key ? (openssl_pkey_get_details($key)['bits'] ?? null) : null;
    }
}
