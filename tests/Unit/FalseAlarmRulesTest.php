<?php

namespace Tests\Unit;

use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Checks\DaneCheck;
use App\Checks\DkimCheck;
use App\Checks\DnssecCheck;
use App\Checks\MtaStsCheck;
use App\Checks\MxCheck;
use App\Checks\PtrCheck;
use App\Checks\SpfCheck;
use App\Checks\TlsRptCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use App\Support\Cloudflare;
use App\Support\Tls\TlsInspector;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

/** Bewertungsregeln gegen Fehlalarme (realistische, gemockte DNS-Antworten). */
class FalseAlarmRulesTest extends TestCase
{
    private const SOA = 'example.com. 3600 IN SOA ns1.example.net. hostmaster.example.com. 2026112201 7200 900 1209600 300';

    private static ?string $key = null;

    private static function key(): string
    {
        return self::$key ??= preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['key']);
    }

    private function ctx(array $records, array $domain = [], bool $authenticated = false): CheckContext
    {
        $dns = new FakeDnsResolver($records);
        $dns->authenticated = $authenticated;

        return new CheckContext(new Domain(['name' => 'example.com', 'inherit_dkim_providers' => false, ...$domain]), $dns, $dns, new AppSetting);
    }

    private function texts(CheckOutcome $out): string
    {
        return implode("\n", array_column($out->details()['messages'], 'text'));
    }

    private function dane(CheckContext $ctx): CheckOutcome
    {
        return (new DaneCheck(new TlsInspector))->run($ctx);
    }

    // ---------------------------------------------------------------- DKIM

    #[Test]
    public function dkim_does_not_report_missing_selectors_of_unused_providers(): void
    {
        $out = (new DkimCheck)->run($this->ctx([
            'example.com. 300 IN MX 1 smtp.google.com.',
            'example.com. 300 IN TXT "v=spf1 include:_spf.google.com ~all"',
            'google._domainkey.example.com. 300 IN TXT "v=DKIM1; k=rsa; p='.self::key().'"',
        ], ['dkim_providers' => ['google', 'm365', 'mailcow', 'generic']]));

        $this->assertSame(CheckStatus::Ok, $out->status(), $this->texts($out));
        $this->assertStringNotContainsString('selector1', $this->texts($out));
        $this->assertStringNotContainsString('Mailcow', $this->texts($out));
    }

    #[Test]
    public function dkim_errors_when_mx_is_microsoft_365_but_selectors_are_missing(): void
    {
        // M365 ist nicht ausgewählt – der MX verrät den Anbieter
        $out = (new DkimCheck)->run($this->ctx([
            'example.com. 300 IN MX 0 example-com.mail.protection.outlook.com.',
            'example.com. 300 IN TXT "v=spf1 include:spf.protection.outlook.com -all"',
        ]));

        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('MX zeigt auf Microsoft 365', $this->texts($out));
        $this->assertSame(1, substr_count($this->texts($out), 'Microsoft 365'), 'nur ein Befund');
    }

    #[Test]
    public function dkim_with_microsoft_365_mx_and_active_selector_is_ok(): void
    {
        $out = (new DkimCheck)->run($this->ctx([
            'example.com. 300 IN MX 0 example-com.mail.protection.outlook.com.',
            'selector1._domainkey.example.com. 3600 IN CNAME selector1-example-com._domainkey.example.onmicrosoft.com.',
            'selector1._domainkey.example.com. 3600 IN TXT "v=DKIM1; k=rsa; p='.self::key().'"',
            'selector2._domainkey.example.com. 3600 IN CNAME selector2-example-com._domainkey.example.onmicrosoft.com.',
        ]));

        $this->assertSame(CheckStatus::Ok, $out->status(), $this->texts($out));
    }

    #[Test]
    public function dkim_errors_only_when_no_provider_has_a_key_and_domain_sends_mail(): void
    {
        $records = [
            'example.com. 300 IN MX 10 mail.example.com.',
            'example.com. 300 IN TXT "v=spf1 mx -all"',
        ];
        $out = (new DkimCheck)->run($this->ctx($records, ['dkim_providers' => ['mailcow', 'generic']]));
        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('Kein gültiger DKIM-Schlüssel gefunden (geprüft: dkim, default, mail)', $this->texts($out));

        // ein Anbieter mit gültigem Schlüssel genügt
        $records[] = 'dkim._domainkey.example.com. 300 IN TXT "v=DKIM1; k=rsa; p='.self::key().'"';
        $this->assertSame(CheckStatus::Ok, (new DkimCheck)->run($this->ctx($records, ['dkim_providers' => ['mailcow', 'generic']]))->status());
    }

    #[Test]
    public function dkim_is_not_applicable_for_domains_without_sending(): void
    {
        foreach ([
            ['example.com. 300 IN TXT "v=spf1 -all"'],
            ['example.com. 300 IN TXT "v=spf1 -all"', 'example.com. 300 IN MX 0 .'],
        ] as $records) {
            $out = (new DkimCheck)->run($this->ctx($records, ['dkim_providers' => ['m365', 'google']]));
            $this->assertSame(CheckStatus::NotApplicable, $out->status(), $this->texts($out));
        }

        // SPF mit Mechanismen = Versand möglich, auch ohne MX
        $out = (new DkimCheck)->run($this->ctx(['example.com. 300 IN TXT "v=spf1 ip4:192.0.2.25 -all"'], ['dkim_providers' => ['google']]));
        $this->assertSame(CheckStatus::Error, $out->status());
    }

    #[Test]
    public function dkim_without_selected_providers_stays_a_hint(): void
    {
        $out = (new DkimCheck)->run($this->ctx(['example.com. 300 IN MX 10 mail.example.com.']));
        $this->assertSame(CheckStatus::Info, $out->status());
        $this->assertStringContainsString('Keine DKIM-Selektoren hinterlegt', $this->texts($out));
    }

    // -------------------------------------------------------------- DNSSEC

    private function signedZone(string $sigExpiry, array $extra = []): array
    {
        return [
            self::SOA,
            str_replace('. 172800 IN DNSKEY', 'example.com. 3600 IN DNSKEY', DnssecTest::ROOT_KSK),
            'example.com. 3600 IN DS 20326 8 2 49AAC11D7B6F6446702E54A1607371607A1A41855200FD2CE1CDDE32F24E8FB5',
            "example.com. 3600 IN RRSIG SOA 8 2 3600 {$sigExpiry} 20261101000000 20326 example.com. c2lnbmF0dXJl",
            ...$extra,
        ];
    }

    #[Test]
    public function dnssec_on_cloudflare_has_no_expiry_warning_but_expired_is_an_error(): void
    {
        $ns = ['example.com. 86400 IN NS ada.ns.cloudflare.com.', 'example.com. 86400 IN NS bob.ns.cloudflare.com.'];

        $soon = $this->signedZone(now()->addHours(2)->utc()->format('YmdHis'), $ns);
        $this->assertSame(CheckStatus::Ok, (new DnssecCheck)->run($this->ctx($soon, authenticated: true))->status());

        $expired = $this->signedZone(now()->subHour()->utc()->format('YmdHis'), $ns);
        $this->assertSame(CheckStatus::Error, (new DnssecCheck)->run($this->ctx($expired, authenticated: true))->status());

        // andere Nameserver: unter 6 Stunden Warnung
        $out = (new DnssecCheck)->run($this->ctx($this->signedZone(now()->addHours(2)->utc()->format('YmdHis')), authenticated: true));
        $this->assertSame(CheckStatus::Warning, $out->status());
    }

    #[Test]
    public function dnssec_signed_zone_without_ds_is_incomplete(): void
    {
        $out = (new DnssecCheck)->run($this->ctx([
            self::SOA,
            str_replace('. 172800 IN DNSKEY', 'example.com. 3600 IN DNSKEY', DnssecTest::ROOT_KSK),
        ]));

        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('DNSSEC unvollständig: DS-Record beim Registrar fehlt', $this->texts($out));
    }

    #[Test]
    public function dnssec_unsigned_zone_is_only_a_hint(): void
    {
        $out = (new DnssecCheck)->run($this->ctx([self::SOA, 'example.com. 300 IN MX 10 mail.example.com.']));
        $this->assertSame(CheckStatus::Info, $out->status());
    }

    #[Test]
    public function dane_on_an_unvalidated_zone_is_one_finding_in_dnssec(): void
    {
        $records = [
            self::SOA,
            'example.com. 300 IN MX 10 mail.example.com.',
            '_25._tcp.mail.example.com. 300 IN TLSA 3 1 1 '.str_repeat('ab', 32),
        ];

        $dnssec = (new DnssecCheck)->run($this->ctx($records));
        $this->assertSame(CheckStatus::Warning, $dnssec->status());
        $this->assertStringContainsString('DANE (TLSA für mail.example.com) ist veröffentlicht', $this->texts($dnssec));

        $dane = $this->dane($this->ctx($records));
        $this->assertFalse($dane->status()->isProblem(), $this->texts($dane));
        $this->assertStringContainsString('siehe Prüfung DNSSEC', $this->texts($dane));
    }

    // ------------------------------------------------------------------ MX

    #[Test]
    public function no_mx_with_cloudflare_proxy_address_warns_about_timeouts(): void
    {
        $out = (new MxCheck)->run($this->ctx([
            'example.com. 300 IN A 104.21.32.1',
            'example.com. 300 IN AAAA 2606:4700:3030::6815:2001',
        ]));

        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('Mails laufen in Timeout. Null-MX (MX 0 .) setzen, falls die Domain keine Mail nutzt.', $this->texts($out));

        $other = (new MxCheck)->run($this->ctx(['example.com. 300 IN A 192.0.2.80']));
        $this->assertSame(CheckStatus::Warning, $other->status());
        $this->assertStringNotContainsString('Timeout', $this->texts($other));
    }

    #[Test]
    public function null_mx_alone_is_ok_and_mixed_with_other_mx_is_an_error(): void
    {
        $out = (new MxCheck)->run($this->ctx(['example.com. 300 IN MX 0 .']));
        $this->assertSame(CheckStatus::Ok, $out->status());
        $this->assertSame('Empfängt bewusst keine Mail (Null-MX)', $out->getSummary());

        $mixed = (new MxCheck)->run($this->ctx([
            'example.com. 300 IN MX 0 .',
            'example.com. 300 IN MX 10 mail.example.com.',
            'mail.example.com. 300 IN A 192.0.2.25',
        ]));
        $this->assertSame(CheckStatus::Error, $mixed->status());
        $this->assertStringContainsString('RFC 7505', $this->texts($mixed));
        $this->assertStringNotContainsString('hat keinen A/AAAA-Record', $this->texts($mixed));
    }

    // ------------------------------------ abhängige Checks ohne Mailempfang

    #[Test]
    public function mail_receiving_checks_are_not_applicable_without_mx_or_with_null_mx(): void
    {
        foreach ([[], ['example.com. 300 IN MX 0 .']] as $records) {
            $ctx = fn () => $this->ctx([self::SOA, 'example.com. 300 IN TXT "v=spf1 -all"', ...$records]);

            $this->assertSame(CheckStatus::NotApplicable, (new MtaStsCheck)->run($ctx())->status());
            $this->assertSame(CheckStatus::NotApplicable, (new TlsRptCheck)->run($ctx())->status());
            $this->assertSame(CheckStatus::NotApplicable, $this->dane($ctx())->status());
            $this->assertSame(CheckStatus::NotApplicable, (new PtrCheck)->run($ctx())->status());
        }
    }

    #[Test]
    public function ptr_still_checks_outgoing_ips_without_mx(): void
    {
        $out = (new PtrCheck)->run($this->ctx(['example.com. 300 IN MX 0 .'], ['extra_ips' => ['192.0.2.25']]));
        $this->assertSame(CheckStatus::Error, $out->status());
        $this->assertStringContainsString('192.0.2.25: kein PTR-Record', $this->texts($out));
    }

    #[Test]
    public function mail_receiving_checks_keep_their_hint_when_the_domain_has_mx(): void
    {
        $ctx = $this->ctx(['example.com. 300 IN MX 10 mail.example.com.', 'mail.example.com. 300 IN A 192.0.2.25']);
        $this->assertSame(CheckStatus::Info, (new TlsRptCheck)->run($ctx)->status());
        $this->assertSame(CheckStatus::Info, $this->dane($ctx)->status());
    }

    // ----------------------------------------------------------------- SPF

    #[Test]
    public function spf_a_mechanism_on_cloudflare_proxy_is_only_a_hint(): void
    {
        $out = (new SpfCheck)->run($this->ctx([
            'example.com. 300 IN TXT "v=spf1 a mx include:_spf.mx.cloudflare.net ~all"',
            'example.com. 300 IN A 172.67.150.10',
            'example.com. 300 IN MX 10 route1.mx.cloudflare.net.',
            '_spf.mx.cloudflare.net. 300 IN TXT "v=spf1 ip4:104.30.0.0/19 ~all"',
        ]));

        $this->assertSame(CheckStatus::Info, $out->status(), $this->texts($out));
        $this->assertStringContainsString('„a“ erlaubt nur Cloudflare-IPs', $this->texts($out));
    }

    // -------------------------------------------------------- Cloudflare

    #[Test]
    public function cloudflare_ranges_are_detected_centrally(): void
    {
        $this->assertTrue(Cloudflare::isProxyIp('104.16.132.229'));
        $this->assertTrue(Cloudflare::isProxyIp('2606:4700::6810:84e5'));
        $this->assertFalse(Cloudflare::isProxyIp('192.0.2.1'));
        $this->assertFalse(Cloudflare::allProxyIps(['104.16.132.229', '192.0.2.1']));
        $this->assertFalse(Cloudflare::allProxyIps([]));
        $this->assertTrue(Cloudflare::isNameserver('ada.ns.cloudflare.com.'));
    }

    // ------------------------------------------------------- Aggregation

    #[Test]
    public function overall_status_ignores_hints_and_not_applicable(): void
    {
        $this->assertSame(CheckStatus::Ok, CheckStatus::overall([CheckStatus::Ok, CheckStatus::Info, CheckStatus::NotApplicable]));
        $this->assertSame(CheckStatus::Warning, CheckStatus::overall([CheckStatus::Info, CheckStatus::Warning]));
        $this->assertSame(CheckStatus::Error, CheckStatus::overall([CheckStatus::Warning, CheckStatus::Error, CheckStatus::Info]));
        $this->assertFalse(CheckStatus::NotApplicable->isProblem());
        $this->assertSame(CheckStatus::NotApplicable, CheckStatus::worst([CheckStatus::NotApplicable]));
    }
}
