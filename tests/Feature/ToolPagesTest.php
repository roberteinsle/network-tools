<?php

namespace Tests\Feature;

use App\Checks\Check;
use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Checks\SrvCheck;
use App\Enums\CheckType;
use App\Enums\DnsTool;
use App\Enums\PublicResolver;
use App\Filament\Pages\Tool;
use App\Models\User;
use App\Services\CheckRegistry;
use App\Services\Lookup\DnsTrace;
use App\Support\Dns\DnsResolver;
use App\Support\Dns\NameserverProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;
use Tests\Unit\DnssecTest;

class ToolPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Zone, die der konfigurierte (rekursive) Resolver kennt. */
    private const ZONE = [
        'example.com. 300 IN NS ns1.example.net.',
        'example.com. 300 IN NS ns2.example.net.',
        'ns1.example.net. 300 IN A 192.0.2.53',
        'ns2.example.net. 300 IN A 198.51.100.53',
        'example.com. 300 IN MX 20 mx2.example.com.',
        'example.com. 300 IN MX 10 mx1.example.com.',
        'mx1.example.com. 300 IN A 192.0.2.25',
        'mx2.example.com. 300 IN A 192.0.2.26',
        'example.com. 3600 IN SOA ns1.example.net. hostmaster.example.com. 2026112201 7200 900 1209600 300',
        'example.com. 300 IN TXT "v=spf1 -all"',
        'example.com. 300 IN TXT "google-site-verification=abc"',
        'example.com. 300 IN CAA 0 issue "letsencrypt.org"',
        'www.example.com. 300 IN CNAME web.example.com.',
        'web.example.com. 300 IN A 203.0.113.10',
        '_imaps._tcp.example.com. 3600 IN SRV 0 1 993 web.example.com.',
        '_autodiscover._tcp.example.com. 3600 IN SRV 0 0 443 gone.example.com.',
        '10.113.0.203.in-addr.arpa. 300 IN PTR web.example.com.',
        'example.com. 3600 IN DS 20326 8 2 49AAC11D7B6F6446702E54A1607371607A1A41855200FD2CE1CDDE32F24E8FB5',
        'web.example.com. 300 IN RRSIG A 13 3 300 20991231000000 20261101000000 4242 example.com. c2lnbmF0dXJl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));

        $this->app->instance(NameserverProbe::class, new class extends NameserverProbe
        {
            public function configured(): DnsResolver
            {
                $dns = new FakeDnsResolver([...ToolPagesTest::zone(), str_replace('. 172800 IN DNSKEY', 'example.com. 3600 IN DNSKEY', DnssecTest::ROOT_KSK)]);
                $dns->authenticated = true;

                return $dns;
            }

            public function resolver(string $ip): DnsResolver
            {
                $root = array_values(DnsTrace::ROOT_SERVERS);
                $dns = new FakeDnsResolver;

                if (in_array($ip, $root, true)) {
                    return $dns->referral('www.example.com', 'A', ['com. 172800 IN NS a.gtld-servers.net.'], ['a.gtld-servers.net. 172800 IN A 192.5.6.30']);
                }
                if ($ip === '192.5.6.30') {
                    return $dns->referral('www.example.com', 'A', ['example.com. 172800 IN NS ns1.example.net.'], ['ns1.example.net. 172800 IN A 192.0.2.53']);
                }

                // autoritative Nameserver der Zone; ns2 liefert noch die alte Adresse
                $address = $ip === '198.51.100.53' ? '203.0.113.9' : '203.0.113.10';
                $dns = new FakeDnsResolver([
                    "www.example.com. 300 IN A {$address}",
                    'www.example.com. 300 IN AAAA 2001:db8::10',
                    'example.com. 3600 IN SOA ns1.example.net. hostmaster.example.com. 2026112201 7200 900 1209600 300',
                ]);
                $dns->authoritative = true;

                return $dns;
            }

            public function recursive(string $ip): DnsResolver
            {
                return new FakeDnsResolver(['www.example.com. 300 IN A 203.0.113.10']);
            }
        });
    }

    public static function zone(): array
    {
        return self::ZONE;
    }

    private function tool(DnsTool $tool, array $form)
    {
        return Livewire::test(Tool::class, ['tool' => $tool->value])
            ->fillForm($form)
            ->call('lookup')
            ->assertHasNoFormErrors();
    }

    #[Test]
    public function every_tool_page_renders_and_is_in_the_navigation(): void
    {
        foreach (DnsTool::cases() as $tool) {
            $this->get("/admin/tools/{$tool->value}")
                ->assertOk()
                ->assertSee($tool->label())
                ->assertSee($tool->group());
        }
        $this->get('/admin/tools/unknown')->assertNotFound();
    }

    #[Test]
    public function old_dns_lookup_address_redirects_to_ip_tool(): void
    {
        $this->get('/admin/dns-lookup?host=www.example.com')->assertRedirect('/admin/tools/ip?q=www.example.com');
    }

    #[Test]
    public function ip_tool_compares_authoritative_and_public_answers(): void
    {
        $this->tool(DnsTool::Ip, ['name' => 'WWW.Example.com.'])
            ->assertSee('Zone example.com')
            ->assertSee('ns1.example.net')
            ->assertSee('203.0.113.9')
            ->assertSee('2001:db8::10')
            ->assertSee(PublicResolver::Google->label())
            ->assertSee(PublicResolver::Yandex->label());
    }

    #[Test]
    public function record_tools_show_their_records(): void
    {
        $mx = $this->tool(DnsTool::Mx, ['name' => 'example.com'])->assertSee('mx1.example.com')->assertSee('192.0.2.25');
        $this->assertLessThan(strpos($mx->html(), 'mx2.example.com'), strpos($mx->html(), 'mx1.example.com')); // nach Priorität

        $this->tool(DnsTool::Nameserver, ['name' => 'example.com'])->assertSee('ns2.example.net')->assertSee('2026112201');
        $this->tool(DnsTool::Soa, ['name' => 'www.example.com'])->assertSee('hostmaster@example.com')->assertSee('1209600 s (14 Tag(e))')->assertDontSee('Kein SOA');
        $this->tool(DnsTool::Cname, ['name' => 'www.example.com'])->assertSee('web.example.com')->assertSee('203.0.113.10');
        $this->tool(DnsTool::Txt, ['name' => 'example.com'])->assertSee('SPF')->assertSee('Google-Verifizierung');
        $this->tool(DnsTool::Caa, ['name' => 'www.example.com'])->assertSee('letsencrypt.org')->assertSee('Geerbt von example.com');
    }

    #[Test]
    public function ptr_tool_confirms_forward_resolution(): void
    {
        $this->tool(DnsTool::Ptr, ['name' => '203.0.113.10'])
            ->assertSee('10.113.0.203.in-addr.arpa')
            ->assertSee('web.example.com')
            ->assertSee('OK');

        Livewire::test(Tool::class, ['tool' => 'ptr'])->fillForm(['name' => 'kein gültiger wert'])->call('lookup')->assertHasFormErrors(['name']);
    }

    #[Test]
    public function trace_follows_referrals_from_the_root(): void
    {
        $this->tool(DnsTool::Trace, ['name' => 'www.example.com'])
            ->assertSee('a.root-servers.net')
            ->assertSee('Verweis auf com')
            ->assertSee('a.gtld-servers.net')
            ->assertSee('Verweis auf example.com')
            ->assertSee('A 203.0.113.10');
    }

    #[Test]
    public function dnssec_and_ds_tools(): void
    {
        $this->tool(DnsTool::Dnssec, ['name' => 'web.example.com', 'record_type' => 'A'])
            ->assertSee('ECDSAP256SHA256 (13)')
            ->assertSee('4242');

        $this->tool(DnsTool::Ds, ['name' => 'example.com'])
            ->assertSee('20326')
            ->assertSee('SHA-256 (2)')
            ->assertSee('KSK (Key Signing Key)');
    }

    #[Test]
    public function mail_security_tools_run_the_registered_check_without_storing(): void
    {
        $seen = new \ArrayObject;
        $this->app->instance(CheckRegistry::class, new class($seen) extends CheckRegistry
        {
            public function __construct(private \ArrayObject $seen) {}

            public function all(): array
            {
                $seen = $this->seen;

                return array_map(fn (CheckType $type) => new class($type, $seen) implements Check
                {
                    public function __construct(private CheckType $type, private \ArrayObject $seen) {}

                    public function type(): CheckType
                    {
                        return $this->type;
                    }

                    public function run(CheckContext $ctx): CheckOutcome
                    {
                        $this->seen[] = [$this->type, $ctx->domain->exists, $ctx->domain->dkim_selectors];

                        return CheckOutcome::make()->warning("Befund {$this->type->value}");
                    }
                }, [CheckType::Spf, CheckType::Dkim, CheckType::Dmarc]);
            }
        });

        $this->tool(DnsTool::Dkim, ['name' => 'example.com', 'dkim_selectors' => ['selector1']])->assertSee('Befund dkim')->assertDontSee('Befund spf');

        $this->assertCount(1, $seen);
        [$type, $exists, $selectors] = $seen[0];
        $this->assertSame(CheckType::Dkim, $type);
        $this->assertFalse($exists);
        $this->assertSame(['selector1'], $selectors);
        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('check_runs', 0);
        Mail::assertNothingSent();

        Livewire::test(Tool::class, ['tool' => 'dkim'])->fillForm(['name' => 'example.com'])->call('lookup')->assertHasErrors(['data.dkim_selectors']);
    }

    #[Test]
    public function srv_tool_runs_the_registered_check_with_suggestions(): void
    {
        // echter SrvCheck aus der Registry, nur mit Test-Zone statt echtem DNS
        $this->app->instance(CheckRegistry::class, new class extends CheckRegistry
        {
            public function all(): array
            {
                return [new class implements Check
                {
                    public function type(): CheckType
                    {
                        return CheckType::Srv;
                    }

                    public function run(CheckContext $ctx): CheckOutcome
                    {
                        $dns = new FakeDnsResolver(ToolPagesTest::zone());

                        return (new SrvCheck)->run(new CheckContext($ctx->domain, $dns, $dns, $ctx->settings));
                    }
                }];
            }
        });

        $this->tool(DnsTool::Srv, ['name' => '_minecraft._tcp.example.com'])
            ->assertSee('web.example.com:993')
            ->assertSee('gone.example.com hat keine A/AAAA-Adresse')
            ->assertSee('_minecraft._tcp.example.com fehlt')
            ->assertSee('Vorschläge');

        $this->assertDatabaseCount('domains', 0);
        Mail::assertNothingSent();
    }
}
