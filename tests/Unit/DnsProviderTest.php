<?php

namespace Tests\Unit;

use App\Enums\DnsProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DnsProviderTest extends TestCase
{
    #[Test]
    public function providers_are_detected_from_nameservers(): void
    {
        $cases = [
            'ada.ns.cloudflare.com.' => DnsProvider::Cloudflare,
            'ns-1234.awsdns-12.org' => DnsProvider::Route53,
            'hydrogen.ns.hetzner.com' => DnsProvider::Hetzner,
            'ns1.your-server.de' => DnsProvider::Hetzner,
            'ns1.artfiles.de' => DnsProvider::Artfiles,
            'ns1045.ui-dns.de' => DnsProvider::Ionos,
            'ns1-04.azure-dns.com' => DnsProvider::Azure,
            'ns-cloud-a1.googledomains.com' => DnsProvider::GoogleCloud,
            'ns.inwx.de' => DnsProvider::Inwx,
            'ns-strato.ui-dns.de' => DnsProvider::Strato,
            'ns1.kas-dns-service.de' => DnsProvider::AllInkl,
        ];
        foreach ($cases as $host => $provider) {
            $this->assertSame($provider, DnsProvider::forNameserver($host), $host);
        }

        $this->assertNull(DnsProvider::forNameserver('ns1.kunde.de'));
        $this->assertNull(DnsProvider::forNameserver('fakecloudflare.com'));
    }

    #[Test]
    public function multi_provider_zones_list_every_provider_once(): void
    {
        $this->assertSame(
            [DnsProvider::Route53, DnsProvider::Cloudflare],
            DnsProvider::detect(['ns-1.awsdns-01.com', 'ns-2.awsdns-02.net', 'ada.ns.cloudflare.com', 'ns1.kunde.de']),
        );
        $this->assertSame([], DnsProvider::detect([]));
    }
}
