<?php

namespace Tests\Unit;

use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Checks\SpfCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class SpfCheckTest extends TestCase
{
    private function check(array $records): CheckOutcome
    {
        $dns = new FakeDnsResolver($records);
        $ctx = new CheckContext(new Domain(['name' => 'example.com']), $dns, $dns, new AppSetting);

        return (new SpfCheck)->run($ctx);
    }

    #[Test]
    public function counts_nested_lookups(): void
    {
        $out = $this->check([
            'example.com. 300 IN TXT "v=spf1 include:a.test mx -all"',
            'example.com. 300 IN MX 10 mail.example.com.',
            'a.test. 300 IN TXT "v=spf1 include:b.test a:host.test ip4:192.0.2.1 ~all"',
            'b.test. 300 IN TXT "v=spf1 ip4:198.51.100.0/24 -all"',
            'host.test. 300 IN A 192.0.2.10',
        ]);

        $this->assertSame(CheckStatus::Ok, $out->status());
        $this->assertSame(4, $out->getState()['lookups']); // include a, include b, a:host, mx
    }

    #[Test]
    public function flags_more_than_ten_lookups_as_error(): void
    {
        $records = ['example.com. 300 IN TXT "v=spf1 '.implode(' ', array_map(fn ($i) => "include:i{$i}.test", range(1, 11))).' -all"'];
        foreach (range(1, 11) as $i) {
            $records[] = "i{$i}.test. 300 IN TXT \"v=spf1 ip4:192.0.2.{$i} -all\"";
        }

        $out = $this->check($records);

        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertSame(11, $out->getState()['lookups']);
        $this->assertStringContainsString('Lookup-Limit überschritten', json_encode($out->details(), JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function redirect_and_void_lookups_are_counted(): void
    {
        $out = $this->check([
            'example.com. 300 IN TXT "v=spf1 a:missing1.test a:missing2.test a:missing3.test redirect=_spf.example.com"',
            '_spf.example.com. 300 IN TXT "v=spf1 ip4:192.0.2.0/24 -all"',
        ]);

        $this->assertSame(4, $out->getState()['lookups']);
        $this->assertSame(CheckStatus::Error, $out->status()); // 3 Void-Lookups > 2
    }

    #[Test]
    public function multiple_records_and_plus_all_are_errors(): void
    {
        $out = $this->check([
            'example.com. 300 IN TXT "v=spf1 +all"',
            'example.com. 300 IN TXT "v=spf1 -all"',
        ]);

        $texts = array_column($out->details()['messages'], 'text');
        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Mehrere SPF-Records')));
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, '+all')));
    }

    #[Test]
    public function missing_spf_is_an_error_for_mail_domains(): void
    {
        $out = $this->check(['example.com. 300 IN MX 10 mail.example.com.']);

        $this->assertSame(CheckStatus::Error, $out->status());
    }
}
