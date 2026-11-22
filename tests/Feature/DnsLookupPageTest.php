<?php

namespace Tests\Feature;

use App\Enums\PublicResolver;
use App\Filament\Pages\DnsLookup;
use App\Models\User;
use App\Support\Dns\DnsResolver;
use App\Support\Dns\NameserverProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class DnsLookupPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));

        $this->app->instance(NameserverProbe::class, new class extends NameserverProbe
        {
            public function configured(): DnsResolver
            {
                return new FakeDnsResolver([
                    'example.com. 300 IN NS ns1.example.net.',
                    'example.com. 300 IN NS ns2.example.net.',
                    'ns1.example.net. 300 IN A 192.0.2.53',
                    'ns2.example.net. 300 IN A 198.51.100.53',
                ]);
            }

            public function resolver(string $ip): DnsResolver
            {
                // ns2 liefert noch die alte Adresse
                $address = $ip === '198.51.100.53' ? '203.0.113.9' : '203.0.113.10';

                return new FakeDnsResolver([
                    "www.example.com. 300 IN A {$address}",
                    'www.example.com. 300 IN AAAA 2001:db8::10',
                ]);
            }

            public function recursive(string $ip): DnsResolver
            {
                return new FakeDnsResolver(['www.example.com. 300 IN A 203.0.113.10']);
            }
        });
    }

    #[Test]
    public function page_renders(): void
    {
        $this->get('/admin/dns-lookup')->assertOk()->assertSee('Hostname');
    }

    #[Test]
    public function shows_ips_from_authoritative_and_public_resolvers(): void
    {
        $page = Livewire::test(DnsLookup::class)
            ->fillForm(['name' => 'WWW.Example.com.'])
            ->call('lookup')
            ->assertHasNoFormErrors()
            ->assertSee('Zone example.com')
            ->assertSee('ns1.example.net')
            ->assertSee('203.0.113.9')
            ->assertSee('2001:db8::10')
            ->assertSee(PublicResolver::Google->label())
            ->assertSee(PublicResolver::Yandex->label());

        $this->assertSame('www.example.com', $page->get('host'));
        $this->assertCount(2, $page->get('result')['authoritative']);
        $this->assertCount(count(PublicResolver::cases()), $page->get('result')['public']);
    }

    #[Test]
    public function invalid_input_is_rejected(): void
    {
        Livewire::test(DnsLookup::class)
            ->fillForm(['name' => 'https://example.com/pfad'])
            ->call('lookup')
            ->assertHasFormErrors(['name' => 'regex']);
    }
}
