<?php

namespace Tests\Unit;

use App\Checks\CheckContext;
use App\Checks\DkimCheck;
use App\Checks\DmarcCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class DmarcAndDkimCheckTest extends TestCase
{
    private function ctx(array $records, array $domainAttrs = []): CheckContext
    {
        $dns = new FakeDnsResolver($records);

        return new CheckContext(new Domain(['name' => 'example.com', ...$domainAttrs]), $dns, $dns, new AppSetting);
    }

    #[Test]
    public function dmarc_reject_is_ok_and_none_is_warning(): void
    {
        $ok = (new DmarcCheck)->run($this->ctx(['_dmarc.example.com. 300 IN TXT "v=DMARC1; p=reject; rua=mailto:dmarc@example.com"']));
        $this->assertSame(CheckStatus::Ok, $ok->status());

        $none = (new DmarcCheck)->run($this->ctx(['_dmarc.example.com. 300 IN TXT "v=DMARC1; p=none; rua=mailto:dmarc@example.com"']));
        $this->assertSame(CheckStatus::Warning, $none->status());

        $missing = (new DmarcCheck)->run($this->ctx([]));
        $this->assertSame(CheckStatus::Error, $missing->status());
    }

    #[Test]
    public function dmarc_external_report_destination_needs_authorization(): void
    {
        $out = (new DmarcCheck)->run($this->ctx(['_dmarc.example.com. 300 IN TXT "v=DMARC1; p=reject; rua=mailto:r@reports.test"']));
        $this->assertSame(CheckStatus::Warning, $out->status());

        $out = (new DmarcCheck)->run($this->ctx([
            '_dmarc.example.com. 300 IN TXT "v=DMARC1; p=reject; rua=mailto:r@reports.test"',
            'example.com._report._dmarc.reports.test. 300 IN TXT "v=DMARC1"',
        ]));
        $this->assertSame(CheckStatus::Ok, $out->status());
    }

    #[Test]
    public function dkim_uses_provider_selectors(): void
    {
        $key = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['key'];
        $p = preg_replace('/-----[^-]+-----|\s/', '', $key);

        $out = (new DkimCheck)->run($this->ctx(
            ["google._domainkey.example.com. 300 IN TXT \"v=DKIM1; k=rsa; p={$p}\""],
            ['inherit_dkim_providers' => false, 'dkim_providers' => ['google', 'm365']],
        ));

        $texts = implode("\n", array_column($out->details()['messages'], 'text'));
        $this->assertStringContainsString('RSA 2048 Bit', $texts);
        $this->assertStringContainsString('Microsoft 365: kein gültiger DKIM-Schlüssel', $texts);
        $this->assertSame(CheckStatus::Error, $out->status());
    }

    private function rsaKey(): string
    {
        $key = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['key'];

        return preg_replace('/-----[^-]+-----|\s/', '', $key);
    }

    /** SES-Token: CNAME auf <token>.dkim.amazonses.com, dort Schlüssel oder leeres p= (Reserve). */
    private function sesToken(string $token, ?string $p, string $domain = 'example.com'): array
    {
        return [
            "{$token}._domainkey.{$domain}. 300 IN CNAME {$token}.dkim.amazonses.com.",
            "{$token}._domainkey.{$domain}. 300 IN TXT \"p=".($p ?? '').'"',
        ];
    }

    #[Test]
    public function ses_reserve_tokens_are_info_and_overall_status_is_ok(): void
    {
        $key = $this->rsaKey();
        $tokens = ['xwhlygyaaaa', 'xwhlygybbbb', 'xwhlygycccc', 'w7qqqaaaa', 'w7qqqbbbb', 'w7qqqcccc'];
        $records = ["dkim._domainkey.einsle.cloud. 300 IN TXT \"v=DKIM1;k=rsa;t=s;s=email;p={$key}\""];
        foreach ($tokens as $i => $token) {
            array_push($records, ...$this->sesToken($token, in_array($i, [0, 3], true) ? $key : null, 'einsle.cloud'));
        }

        $dns = new FakeDnsResolver($records);
        $out = (new DkimCheck)->run(new CheckContext(
            new Domain(['name' => 'einsle.cloud', 'inherit_dkim_providers' => false, 'dkim_providers' => ['mailcow'], 'dkim_selectors' => $tokens]),
            $dns, $dns, new AppSetting,
        ));

        $messages = $out->details()['messages'];
        $texts = implode("\n", array_column($messages, 'text'));
        $this->assertSame(CheckStatus::Ok, $out->status(), $texts);
        $this->assertStringContainsString('dkim: RSA 2048 Bit', $texts);
        $this->assertStringContainsString('AWS SES: 2 aktive(r) Schlüssel (xwhlygy…, w7qqqaa…)', $texts);
        $this->assertSame(4, substr_count($texts, 'Rotations-Reserve (AWS SES)'));
        $this->assertNotContains('warning', array_column($messages, 'status'));
        $this->assertStringNotContainsString('widerrufen', $texts);
    }

    #[Test]
    public function ses_without_any_active_key_is_a_warning(): void
    {
        $records = [...$this->sesToken('aaaa', null), ...$this->sesToken('bbbb', null), ...$this->sesToken('cccc', null)];
        $out = (new DkimCheck)->run($this->ctx($records, ['inherit_dkim_providers' => false, 'dkim_selectors' => ['aaaa', 'bbbb', 'cccc']]));

        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('AWS SES: kein Token mit gültigem Schlüssel', implode("\n", array_column($out->details()['messages'], 'text')));
    }

    #[Test]
    public function revoked_key_outside_ses_is_still_a_warning(): void
    {
        $key = $this->rsaKey();
        $out = (new DkimCheck)->run($this->ctx([
            "s1._domainkey.example.com. 300 IN TXT \"v=DKIM1; k=rsa; p={$key}\"",
            's2._domainkey.example.com. 300 IN TXT "v=DKIM1; k=rsa; p="',
        ], ['inherit_dkim_providers' => false, 'dkim_selectors' => ['s1', 's2']]));

        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('s2: Schlüssel widerrufen', implode("\n", array_column($out->details()['messages'], 'text')));
    }
}
