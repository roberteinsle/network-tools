<?php

namespace Tests\Unit;

use App\Checks\CaaCheck;
use App\Checks\CheckContext;
use App\Checks\NsCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use App\Support\Dns\DnsResolver;
use App\Support\Dns\NameserverProbe;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class CaaAndNsCheckTest extends TestCase
{
    private function ctx(array $records, string $name = 'example.com'): CheckContext
    {
        $dns = new FakeDnsResolver($records);

        return new CheckContext(new Domain(['name' => $name]), $dns, $dns, new AppSetting);
    }

    private function texts($out): string
    {
        return implode("\n", array_column($out->details()['messages'], 'text'));
    }

    #[Test]
    public function caa_missing_is_info_and_allowed_ca_is_ok(): void
    {
        $this->assertSame(CheckStatus::Info, (new CaaCheck)->run($this->ctx([]))->status());

        $out = (new CaaCheck)->run($this->ctx(['example.com. 300 IN CAA 0 issue "letsencrypt.org"']));
        $this->assertSame(CheckStatus::Ok, $out->status());
        $this->assertStringContainsString('letsencrypt.org', $this->texts($out));
    }

    #[Test]
    public function caa_is_inherited_from_parent_name(): void
    {
        $out = (new CaaCheck)->run($this->ctx(['example.com. 300 IN CAA 0 issue "sectigo.com"'], 'shop.example.com'));
        $this->assertSame('example.com', $out->details()['data']['Geerbt von']);
        $this->assertSame(CheckStatus::Ok, $out->status());
    }

    #[Test]
    public function caa_forbidding_all_cas_warns_and_unknown_critical_tag_errors(): void
    {
        $this->assertSame(CheckStatus::Warning, (new CaaCheck)->run($this->ctx(['example.com. 300 IN CAA 0 issue ";"']))->status());

        $out = (new CaaCheck)->run($this->ctx([
            'example.com. 300 IN CAA 0 issue "letsencrypt.org"',
            'example.com. 300 IN CAA 128 future "x"',
        ]));
        $this->assertSame(CheckStatus::Error, $out->status());
    }

    /** @param array<string, FakeDnsResolver> $servers IP => autoritative Zone */
    private function ns(array $records, array $servers)
    {
        $probe = new class($servers) extends NameserverProbe
        {
            public function __construct(private array $servers) {}

            public function resolver(string $ip): DnsResolver
            {
                return $this->servers[$ip] ?? new FakeDnsResolver;
            }
        };

        return (new NsCheck($probe))->run($this->ctx($records));
    }

    private function authoritative(array $records, bool $aa = true): FakeDnsResolver
    {
        $dns = new FakeDnsResolver($records);
        $dns->authoritative = $aa;

        return $dns;
    }

    private const DELEGATION = [
        'example.com. 300 IN NS ns1.example.net.',
        'example.com. 300 IN NS ns2.example.org.',
        'ns1.example.net. 300 IN A 192.0.2.1',
        'ns2.example.org. 300 IN A 198.51.100.1',
    ];

    private function zone(int $serial): array
    {
        return [
            "example.com. 300 IN SOA ns1.example.net. hostmaster.example.com. {$serial} 7200 3600 1209600 3600",
            'example.com. 300 IN NS ns1.example.net.',
            'example.com. 300 IN NS ns2.example.org.',
        ];
    }

    #[Test]
    public function ns_ok_when_all_servers_answer_authoritatively_with_same_serial(): void
    {
        $out = $this->ns(self::DELEGATION, [
            '192.0.2.1' => $this->authoritative($this->zone(2026111701)),
            '198.51.100.1' => $this->authoritative($this->zone(2026111701)),
        ]);
        $this->assertSame(CheckStatus::Ok, $out->status(), $this->texts($out));
        $this->assertArrayNotHasKey('serial', $out->getState());
    }

    #[Test]
    public function ns_detects_serial_mismatch_and_lame_delegation(): void
    {
        $out = $this->ns(self::DELEGATION, [
            '192.0.2.1' => $this->authoritative($this->zone(2026111701)),
            '198.51.100.1' => $this->authoritative($this->zone(2026111600)),
        ]);
        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('SOA-Serial unterschiedlich', $this->texts($out));

        $out = $this->ns(self::DELEGATION, [
            '192.0.2.1' => $this->authoritative($this->zone(2026111701)),
            '198.51.100.1' => $this->authoritative($this->zone(2026111701), aa: false),
        ]);
        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('Lame Delegation', $this->texts($out));
    }

    #[Test]
    public function different_serials_of_multiple_dns_providers_are_only_info(): void
    {
        $other = $this->zone(1);
        $other[0] = 'example.com. 300 IN SOA ns2.example.org. hostmaster.example.com. 1 7200 3600 1209600 3600';
        $out = $this->ns(self::DELEGATION, [
            '192.0.2.1' => $this->authoritative($this->zone(2026111701)),
            '198.51.100.1' => $this->authoritative($other),
        ]);
        $this->assertSame(CheckStatus::Info, $out->status(), $this->texts($out));
    }

    #[Test]
    public function single_nameserver_is_a_warning(): void
    {
        $out = $this->ns(['example.com. 300 IN NS ns1.example.net.', 'ns1.example.net. 300 IN A 192.0.2.1'], [
            '192.0.2.1' => $this->authoritative([
                'example.com. 300 IN SOA ns1.example.net. hostmaster.example.com. 1 7200 3600 1209600 3600',
                'example.com. 300 IN NS ns1.example.net.',
            ]),
        ]);
        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('Nur ein Nameserver', $this->texts($out));
    }
}
