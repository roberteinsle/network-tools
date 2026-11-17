<?php

namespace Tests\Unit;

use App\Checks\CheckContext;
use App\Checks\DaneCheck;
use App\Checks\HttpCheck;
use App\Enums\CheckStatus;
use App\Models\AppSetting;
use App\Models\Domain;
use App\Support\Tls\CertificateInfo;
use App\Support\Tls\TlsInspector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

class HttpAndDaneCheckTest extends TestCase
{
    private function ctx(array $records, array $domainAttrs = [], bool $authenticated = false): CheckContext
    {
        $dns = new FakeDnsResolver($records);
        $dns->authenticated = $authenticated;

        return new CheckContext(new Domain(['name' => 'example.com', ...$domainAttrs]), $dns, $dns, new AppSetting);
    }

    private function texts($out): string
    {
        return implode("\n", array_column($out->details()['messages'], 'text'));
    }

    private function web(): CheckContext
    {
        return $this->ctx(['example.com. 300 IN A 192.0.2.10'], ['web_hosts' => ['example.com']]);
    }

    #[Test]
    public function redirect_to_https_with_long_hsts_is_ok(): void
    {
        Http::fake([
            'http://example.com/' => Http::response('', 301, ['Location' => 'https://example.com/']),
            'https://example.com/' => Http::response('ok', 200, ['Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload']),
        ]);

        $out = (new HttpCheck)->run($this->web());
        $this->assertSame(CheckStatus::Ok, $out->status(), $this->texts($out));
        $this->assertSame(['http' => 'https', 'hsts' => ['max_age' => 63072000, 'sub' => true, 'preload' => true]], $out->getState()['example.com']);
    }

    #[Test]
    public function relative_redirect_chain_is_followed(): void
    {
        Http::fake([
            'http://example.com/' => Http::response('', 302, ['Location' => '/start']),
            'http://example.com/start' => Http::response('', 301, ['Location' => 'https://example.com/start']),
            'https://example.com/' => Http::response('ok', 200, ['Strict-Transport-Security' => 'max-age=31536000']),
        ]);

        $this->assertSame(CheckStatus::Ok, (new HttpCheck)->run($this->web())->status());
    }

    #[Test]
    public function missing_redirect_and_missing_hsts_are_warnings(): void
    {
        Http::fake([
            'http://example.com/' => Http::response('plain', 200),
            'https://example.com/' => Http::response('ok', 200),
        ]);

        $out = (new HttpCheck)->run($this->web());
        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('leitet nicht auf HTTPS um', $this->texts($out));
        $this->assertStringContainsString('kein HSTS-Header', $this->texts($out));
    }

    #[Test]
    public function closed_port_80_is_only_info(): void
    {
        Http::fake([
            'http://example.com/' => fn () => throw new ConnectionException('refused'),
            'https://example.com/' => Http::response('ok', 200, ['Strict-Transport-Security' => 'max-age=31536000']),
        ]);

        $out = (new HttpCheck)->run($this->web());
        $this->assertSame(CheckStatus::Info, $out->status(), $this->texts($out));
    }

    /** Selbstsigniertes Zertifikat und der passende TLSA-Hash (3 1 1). @return array{0:CertificateInfo, 1:string} */
    private function cert(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'mx.example.com'], $key), null, $key, 30);
        openssl_x509_export($x509, $pem);
        $spki = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($key)['key']));

        $info = new CertificateInfo('mx.example.com', 'self', ['mx.example.com'], CarbonImmutable::now(), CarbonImmutable::now()->addDays(30), 'fp', false, null, 'TLSv1.3', $pem, [$pem]);

        return [$info, hash('sha256', $spki)];
    }

    private function tls(CertificateInfo $cert): TlsInspector
    {
        return new class($cert) extends TlsInspector
        {
            public function __construct(private CertificateInfo $cert) {}

            public function starttls(string $host, int $port = 25): CertificateInfo
            {
                return $this->cert;
            }
        };
    }

    private function mx(string $tlsa): array
    {
        return [
            'example.com. 300 IN MX 10 mx.example.com.',
            "_25._tcp.mx.example.com. 300 IN TLSA {$tlsa}",
        ];
    }

    #[Test]
    public function dane_matching_certificate_is_ok(): void
    {
        [$cert, $hash] = $this->cert();
        $out = (new DaneCheck($this->tls($cert)))->run($this->ctx($this->mx("3 1 1 {$hash}"), ['check_mx_tls' => true], authenticated: true));

        $this->assertSame(CheckStatus::Ok, $out->status(), $this->texts($out));
        $this->assertStringContainsString('passt zu TLSA 3 1 1', $this->texts($out));
    }

    #[Test]
    public function dane_mismatch_is_error_and_unsigned_tlsa_is_warning(): void
    {
        [$cert] = $this->cert();
        $wrong = str_repeat('ab', 32);

        $out = (new DaneCheck($this->tls($cert)))->run($this->ctx($this->mx("3 1 1 {$wrong}"), ['check_mx_tls' => true], authenticated: true));
        $this->assertSame(CheckStatus::Error, $out->status());

        $out = (new DaneCheck($this->tls($cert)))->run($this->ctx($this->mx("3 1 1 {$wrong}")));
        $this->assertSame(CheckStatus::Warning, $out->status());
        $this->assertStringContainsString('ohne gültige DNSSEC-Signatur', $this->texts($out));
        $this->assertStringContainsString('nur mit aktivierter STARTTLS-Prüfung', $this->texts($out));
    }

    #[Test]
    public function no_tlsa_is_info(): void
    {
        [$cert] = $this->cert();
        $out = (new DaneCheck($this->tls($cert)))->run($this->ctx(['example.com. 300 IN MX 10 mx.example.com.']));
        $this->assertSame(CheckStatus::Info, $out->status());
    }
}
