<?php

namespace Tests\Unit;

use App\Checks\CheckContext;
use App\Checks\SrvCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class SrvCheckTest extends TestCase
{
    private function check(array $records, array $expected = [])
    {
        $dns = new FakeDnsResolver($records);
        $ctx = new CheckContext(new Domain(['name' => 'example.com', 'srv_services' => $expected]), $dns, $dns, new AppSetting);

        return (new SrvCheck)->run($ctx);
    }

    private function texts($out): string
    {
        return implode("\n", array_column($out->details()['messages'], 'text'));
    }

    #[Test]
    public function no_srv_records_is_neutral_info(): void
    {
        $out = $this->check([]);
        $this->assertSame(CheckStatus::Info, $out->status());
        $this->assertArrayNotHasKey('Vorschläge', $out->details()['data']);
    }

    #[Test]
    public function valid_records_are_ok(): void
    {
        $out = $this->check([
            '_imaps._tcp.example.com. 3600 IN SRV 0 1 993 mail.example.com.',
            '_autodiscover._tcp.example.com. 3600 IN SRV 0 0 443 mail.example.com.',
            'mail.example.com. 300 IN A 192.0.2.25',
        ]);
        $this->assertSame(CheckStatus::Ok, $out->status());
        $this->assertStringContainsString('mail.example.com:993', $this->texts($out));
        $this->assertStringContainsString('2 SRV-Dienst(e)', $out->getSummary());
    }

    #[Test]
    public function unresolvable_target_is_error_with_suggestion(): void
    {
        $out = $this->check(['_imaps._tcp.example.com. 3600 IN SRV 0 1 993 old.example.com.']);
        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('keine A/AAAA-Adresse', $this->texts($out));
        $this->assertStringContainsString('old.example.com: A/AAAA-Record anlegen', $out->details()['data']['Vorschläge']);
    }

    #[Test]
    public function cname_target_warns_and_suggests_the_alias_target(): void
    {
        $out = $this->check([
            '_caldavs._tcp.example.com. 3600 IN SRV 0 1 443 dav.example.com.',
            'dav.example.com. 300 IN CNAME host.provider.net.',
            'dav.example.com. 300 IN A 192.0.2.80',
        ]);
        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('_caldavs._tcp.example.com. 3600 IN SRV 0 1 443 host.provider.net.', $out->details()['data']['Vorschläge']);
    }

    #[Test]
    public function port_zero_is_error(): void
    {
        $out = $this->check([
            '_submission._tcp.example.com. 3600 IN SRV 0 1 0 mail.example.com.',
            'mail.example.com. 300 IN A 192.0.2.25',
        ]);
        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('IN SRV 0 1 587 mail.example.com.', $out->details()['data']['Vorschläge']);
    }

    #[Test]
    public function null_target_is_info_unless_mixed_or_expected(): void
    {
        $this->assertSame(CheckStatus::Info, $this->check(['_pop3._tcp.example.com. 3600 IN SRV 0 0 0 .'])->status());

        $mixed = $this->check([
            '_pop3._tcp.example.com. 3600 IN SRV 0 0 0 .',
            '_pop3._tcp.example.com. 3600 IN SRV 10 0 110 mail.example.com.',
            'mail.example.com. 300 IN A 192.0.2.25',
        ]);
        $this->assertSame(CheckStatus::Error, $mixed->status());

        $this->assertSame(CheckStatus::Warning, $this->check(['_pop3._tcp.example.com. 3600 IN SRV 0 0 0 .'], ['_pop3._tcp'])->status());
    }

    #[Test]
    public function missing_expected_service_warns_and_known_missing_does_not(): void
    {
        $out = $this->check([], ['_minecraft._tcp', '_IMAPS._tcp.', 'kein dienst']);
        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('_minecraft._tcp.example.com fehlt', $this->texts($out));
        $this->assertStringContainsString('_imaps._tcp.example.com. 3600 IN SRV 0 1 993 <host>.', $out->details()['data']['Vorschläge']);
        $this->assertSame(['_imaps._tcp', '_minecraft._tcp'], collect($out->getState()['missing'])->sort()->values()->all());
    }

    #[Test]
    public function nonstandard_port_and_retired_skype_target_are_hints(): void
    {
        $out = $this->check([
            '_sip._tls.example.com. 3600 IN SRV 100 1 443 sipdir.online.lync.com.',
            'sipdir.online.lync.com. 300 IN A 192.0.2.99',
            '_imaps._tcp.example.com. 3600 IN SRV 0 1 9993 mail.example.com.',
            'mail.example.com. 300 IN A 192.0.2.25',
        ]);
        $this->assertSame(CheckStatus::Ok, $out->status());
        $this->assertStringContainsString('Skype for Business Online', $this->texts($out));
        $this->assertStringContainsString('Port 9993 statt des üblichen Ports 993', $this->texts($out));
    }
}
