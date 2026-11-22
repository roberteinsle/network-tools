<?php

namespace App\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DkimProvider;
use App\Support\Dns\DnsException;

/**
 * Prüft die DKIM-Selektoren der für Kunde/Domain gewählten Anbieter (M365, Google …),
 * eigene Selektoren und – wenn der MX auf Exchange Online zeigt – selector1/selector2.
 * Fehlende Selektoren nicht genutzter Anbieter werden nicht gemeldet. Fehler gibt es nur,
 * wenn die Domain Mail versendet und für keinen Anbieter ein gültiger Schlüssel existiert,
 * oder wenn der MX auf Microsoft 365 zeigt und dort kein Schlüssel aktiv ist.
 */
class DkimCheck implements Check
{
    public function type(): CheckType
    {
        return CheckType::Dkim;
    }

    public function run(CheckContext $ctx): CheckOutcome
    {
        // Hinweise (z. B. Rotations-Reserve) sollen ein sonst gültiges DKIM nicht auf „Hinweis“ ziehen
        $out = CheckOutcome::make()->infoIsNeutral();

        if (! $ctx->sendsMail()) {
            return $out->notApplicable('Domain versendet keine Mail (SPF „v=spf1 -all“, kein MX bzw. Null-MX) – DKIM nicht relevant.')->state(['relevant' => false]);
        }

        $groups = [];
        foreach ($ctx->domain->effectiveDkimProviders() as $provider) {
            $groups[$provider->getLabel()] = $provider->selectors();
        }
        $m365 = DkimProvider::Microsoft365->getLabel();
        $mxIsM365 = self::mxIsMicrosoft365($ctx);
        if ($mxIsM365) {
            $groups[$m365] ??= DkimProvider::Microsoft365->selectors();
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
        $ses = [];
        $checkedSelectors = [];
        $m365Missing = false;

        foreach ($groups as $label => $selectors) {
            $validInGroup = 0;
            $found = [];
            $checked = 0;

            foreach ($selectors as $selector) {
                $result = $this->inspect($ctx, $selector);
                $state[$selector] = $result['state'];
                $out->data("{$selector}._domainkey", $result['display']);

                // AWS SES Easy DKIM: gemeinsam bewerten (aktive Tokens + Rotations-Reserve), nicht pro Gruppe
                if ($result['ses']) {
                    $ses[$selector] = $result;

                    continue;
                }

                $checked++;
                if ($result['valid']) {
                    $validInGroup++;
                    $found[] = $selector;
                    $out->add($result['status'], "{$selector}: {$result['text']}");
                } elseif ($result['exists']) {
                    $out->add($result['status'], "{$selector}: {$result['text']}");
                }
            }

            $validCount += $validInGroup;
            if ($checked > 0) {
                $checkedSelectors = [...$checkedSelectors, ...array_diff($selectors, array_keys($ses))];
            }

            // Fehlende Selektoren nicht genutzter Anbieter werden nicht gemeldet – außer M365 laut MX
            if ($label === $m365 && $mxIsM365 && $checked > 0 && $validInGroup === 0) {
                $m365Missing = true;
                $out->error('MX zeigt auf Microsoft 365, aber selector1/selector2 haben keinen gültigen Schlüssel – Exchange Online signiert nicht mit DKIM. DKIM im Defender-Portal aktivieren und die beiden CNAMEs anlegen.');
            } elseif ($label === $m365 && $validInGroup === 1) {
                $out->info("{$label}: nur ".implode(', ', $found).' aktiv – für die Schlüsselrotation sollten selector1 und selector2 existieren.');
            }
        }

        $sesActive = $ses === [] ? [] : $this->evaluateSes($out, $ses);
        $validCount += count($sesActive);

        if ($validCount === 0 && ! $m365Missing && ($checkedSelectors !== [] || $ses !== [])) {
            $tried = array_values(array_unique([...$checkedSelectors, ...array_map(fn ($s) => mb_strimwidth($s, 0, 8, '…'), array_keys($ses))]));
            $out->error('Kein gültiger DKIM-Schlüssel gefunden (geprüft: '.implode(', ', $tried).') – die Domain versendet Mail, Empfänger sehen keine DKIM-Signatur.');
        }

        if (! $out->status()->isProblem()) {
            $out->summary("{$validCount} gültige(r) DKIM-Schlüssel".($ses !== [] ? ', davon '.count($sesActive).' AWS SES' : ''));
        }

        return $out->state($state);
    }

    /**
     * AWS SES Easy DKIM legt pro Identity drei CNAMEs auf <token>.dkim.amazonses.com an; nur einer
     * trägt jeweils den aktiven Schlüssel, die anderen liefern absichtlich p= als Reserve für die
     * automatische Rotation. Bewertet wird deshalb die Gruppe: mindestens ein gültiger Schlüssel = OK.
     *
     * @param  array<string, array>  $ses  Selektor => Ergebnis von inspect()
     * @return list<string> Selektoren mit gültigem Schlüssel
     */
    private function evaluateSes(CheckOutcome $out, array $ses): array
    {
        $active = [];
        $reserve = [];
        foreach ($ses as $selector => $result) {
            if ($result['valid']) {
                $active[] = $selector;
                $out->add($result['status'], "{$selector}: {$result['text']} (AWS SES)");
            } else {
                $reserve[] = $selector;
            }
        }

        $short = fn (array $list) => implode(', ', array_map(fn ($s) => mb_strimwidth($s, 0, 8, '…'), $list));

        if ($active === []) {
            $out->warning('AWS SES: kein Token mit gültigem Schlüssel ('.$short($reserve).') – SES signiert so nicht mit DKIM.');

            return [];
        }

        // Reserve-Tokens ohne Schlüssel sind gewollt und werden nicht gemeldet
        $out->ok('AWS SES: '.count($active).' aktive(r) Schlüssel ('.$short($active).').');

        return $active;
    }

    /** @return array{exists:bool, valid:bool, status:CheckStatus, text:string, display:string, state:mixed, ses:bool} */
    private function inspect(CheckContext $ctx, string $selector): array
    {
        $result = $this->lookup($ctx, $selector);
        $cname = is_array($result['state']) ? ($result['state']['cname'] ?? null) : null;

        return [...$result, 'ses' => self::isSes($cname)];
    }

    /** Mindestens ein MX zeigt auf Exchange Online (*.mail.protection.outlook.com). */
    private static function mxIsMicrosoft365(CheckContext $ctx): bool
    {
        foreach ($ctx->mxHosts() as $host) {
            if (str_ends_with($host, '.mail.protection.outlook.com')) {
                return true;
            }
        }

        return false;
    }

    /** CNAME auf *.dkim.amazonses.com (jede Region) = Token von AWS SES Easy DKIM. */
    private static function isSes(?string $cname): bool
    {
        return $cname !== null && str_ends_with($cname, '.dkim.amazonses.com');
    }

    private function lookup(CheckContext $ctx, string $selector): array
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
