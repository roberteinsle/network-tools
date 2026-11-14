<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CheckType: string implements HasLabel
{
    case Mx = 'mx';
    case Address = 'address';
    case Ptr = 'ptr';
    case Spf = 'spf';
    case Dkim = 'dkim';
    case Dmarc = 'dmarc';
    case MtaSts = 'mta_sts';
    case TlsRpt = 'tls_rpt';
    case Bimi = 'bimi';
    case Dnssec = 'dnssec';
    case TlsWeb = 'tls_web';
    case TlsMx = 'tls_mx';
    case DomainExpiry = 'domain_expiry';
    case Blacklist = 'blacklist';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mx => 'MX',
            self::Address => 'A / AAAA',
            self::Ptr => 'PTR (Reverse DNS)',
            self::Spf => 'SPF',
            self::Dkim => 'DKIM',
            self::Dmarc => 'DMARC',
            self::MtaSts => 'MTA-STS',
            self::TlsRpt => 'TLS-RPT',
            self::Bimi => 'BIMI',
            self::Dnssec => 'DNSSEC',
            self::TlsWeb => 'TLS-Zertifikat (HTTPS)',
            self::TlsMx => 'TLS-Zertifikat (MX / STARTTLS)',
            self::DomainExpiry => 'Domain-Ablauf',
            self::Blacklist => 'Blacklists',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
