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
}
