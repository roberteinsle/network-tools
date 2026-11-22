<?php

namespace Tests\Unit;

use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Checks\DnssecCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use App\Support\Dns\Dnssec;
use NetDNS2\RR;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class DnssecTest extends TestCase
{
    /** Root-KSK-2017, bekannter Key-Tag 20326. */
    public const ROOT_KSK = '. 172800 IN DNSKEY 257 3 8 AwEAAaz/tAm8yTn4Mfeh5eyI96WSVexTBAvkMgJzkKTOiW1vkIbzxeF3+/4RgWOq7HrxRixHlFlExOLAJr5emLvN7SWXgnLh4+B5xQlNVz8Og8kvArMtNROxVQuCaSnIDdD5LKyWbRd2n9WGe2R8PzgCmr3EgVLrjyBxWezF0jLHwVN8efS3rCj/EWgvIWgb9tarpVUDK/b58Da+sqqls3eNbuv7pr+eoZG+SrDK6nWeL3c6H5Apxz7LjVc1uTIdsIXxuOLYA4/ilBmSVIzuDWfdRUfhHdY6+cn8HFRm+2hM8AnXGXws9555KrUB5qihylGa8subX2Nn6UwNR1AkUTV74bU=';

    #[Test]
    public function key_tag_matches_rfc_4034(): void
    {
        $this->assertSame(20326, Dnssec::keyTag(RR::fromString(self::ROOT_KSK)));
    }

    private function zone(string $dsTag, string $sigExpiry): FakeDnsResolver
    {
        $key = str_replace('. 172800 IN DNSKEY', 'example.com. 3600 IN DNSKEY', self::ROOT_KSK);
        $dns = new FakeDnsResolver([
            'example.com. 3600 IN SOA ns1.example.net. hostmaster.example.com. 1 7200 900 1209600 300',
            $key,
            "example.com. 3600 IN DS {$dsTag} 8 2 49AAC11D7B6F6446702E54A1607371607A1A41855200FD2CE1CDDE32F24E8FB5",
            "example.com. 3600 IN RRSIG SOA 8 2 3600 {$sigExpiry} 20261101000000 20326 example.com. c2lnbmF0dXJl",
        ]);
        $dns->authenticated = true;

        return $dns;
    }

    private function check(FakeDnsResolver $dns): CheckOutcome
    {
        return (new DnssecCheck)->run(new CheckContext(new Domain(['name' => 'example.com']), $dns, $dns, new AppSetting));
    }

    #[Test]
    public function matching_ds_and_valid_signatures_are_ok(): void
    {
        $out = $this->check($this->zone('20326', now()->addDays(20)->utc()->format('YmdHis')));

        $this->assertSame(CheckStatus::Ok, $out->status());
    }

    #[Test]
    public function ds_without_matching_dnskey_is_an_error(): void
    {
        $out = $this->check($this->zone('11111', now()->addDays(20)->utc()->format('YmdHis')));

        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('Kein DS-Record passt', implode("\n", array_column($out->details()['messages'], 'text')));
    }

    #[Test]
    public function expiring_and_expired_signatures_are_reported(): void
    {
        // Warnung erst unter 6 Stunden Restlaufzeit
        $this->assertSame(CheckStatus::Ok, $this->check($this->zone('20326', now()->addHours(30)->utc()->format('YmdHis')))->status());
        $this->assertSame(CheckStatus::Warning, $this->check($this->zone('20326', now()->addHours(3)->utc()->format('YmdHis')))->status());
        $this->assertSame(CheckStatus::Error, $this->check($this->zone('20326', now()->subDay()->utc()->format('YmdHis')))->status());
    }
}
