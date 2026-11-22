<?php

namespace App\Enums;

/**
 * Registry der DNS-Anbieter, erkannt an den Nameserver-Hostnamen (NS-Records).
 * Neue Anbieter nur hier eintragen. Eigene Nameserver-Namen (z. B. ns1.kunde.de)
 * lassen sich nicht zuordnen – dafür gibt es das Feld „DNS-Verwaltung“ an der Domain.
 */
enum DnsProvider: string
{
    case Cloudflare = 'cloudflare';
    case Route53 = 'route53';
    case Hetzner = 'hetzner';
    case Artfiles = 'artfiles';
    // Strato vor IONOS: Strato nutzt die IONOS-Nameserver mit eigenem Präfix (ns-strato.ui-dns.*)
    case Strato = 'strato';
    case Ionos = 'ionos';
    case Inwx = 'inwx';
    case Netcup = 'netcup';
    case AllInkl = 'all-inkl';
    case UnitedDomains = 'united-domains';
    case DomainFactory = 'domainfactory';
    case Azure = 'azure';
    case Microsoft365 = 'microsoft365';
    case GoogleCloud = 'google-cloud';
    case DigitalOcean = 'digitalocean';
    case Ovh = 'ovh';
    case Gandi = 'gandi';
    case GoDaddy = 'godaddy';
    case Namecheap = 'namecheap';
    case Ns1 = 'ns1';
    case Desec = 'desec';
    case Vercel = 'vercel';

    public function label(): string
    {
        return match ($this) {
            self::Cloudflare => 'Cloudflare',
            self::Route53 => 'AWS Route 53',
            self::Hetzner => 'Hetzner',
            self::Artfiles => 'Artfiles',
            self::Ionos => 'IONOS',
            self::Strato => 'Strato',
            self::Inwx => 'INWX',
            self::Netcup => 'netcup',
            self::AllInkl => 'ALL-INKL.COM',
            self::UnitedDomains => 'united-domains',
            self::DomainFactory => 'DomainFactory',
            self::Azure => 'Azure DNS',
            self::Microsoft365 => 'Microsoft 365',
            self::GoogleCloud => 'Google Cloud DNS',
            self::DigitalOcean => 'DigitalOcean',
            self::Ovh => 'OVHcloud',
            self::Gandi => 'Gandi',
            self::GoDaddy => 'GoDaddy',
            self::Namecheap => 'Namecheap',
            self::Ns1 => 'NS1',
            self::Desec => 'deSEC',
            self::Vercel => 'Vercel',
        };
    }

    /** Regex auf den Nameserver-Hostnamen (klein, ohne Punkt am Ende). */
    public function pattern(): string
    {
        return match ($this) {
            self::Cloudflare => '/\.ns\.cloudflare\.com$/',
            self::Route53 => '/(^|\.)awsdns-\d+\.(com|net|org|co\.uk)$/',
            self::Hetzner => '/(^|\.)(ns\.hetzner\.(com|de)|your-server\.de|second-ns\.(com|de))$/',
            self::Artfiles => '/(^|\.)artfiles\.de$/',
            self::Ionos => '/(^|\.)ui-dns\.(com|de|org|biz)$/',
            self::Strato => '/((^|\.)rzone\.de|^ns-strato\.ui-dns\.(com|de|org|biz))$/',
            self::Inwx => '/(^|\.)inwx\.(de|eu|net|com|ch|at)$/',
            self::Netcup => '/(^|\.)netcup\.net$/',
            self::AllInkl => '/(^|\.)(kasserver\.com|kas-dns-service\.de)$/',
            self::UnitedDomains => '/(^|\.)udag\.(de|net|org)$/',
            self::DomainFactory => '/(^|\.)ispgateway\.de$/',
            self::Azure => '/(^|\.)azure-dns\.(com|net|org|info)$/',
            self::Microsoft365 => '/(^|\.)bdm\.microsoftonline\.com$/',
            self::GoogleCloud => '/^ns-cloud-[a-z]\d+\.googledomains\.com$/',
            self::DigitalOcean => '/(^|\.)digitalocean\.com$/',
            self::Ovh => '/(^|\.)ovh\.(net|ca)$/',
            self::Gandi => '/(^|\.)gandi\.net$/',
            self::GoDaddy => '/(^|\.)domaincontrol\.com$/',
            self::Namecheap => '/(^|\.)registrar-servers\.com$/',
            self::Ns1 => '/(^|\.)nsone\.net$/',
            self::Desec => '/(^|\.)desec\.(io|org)$/',
            self::Vercel => '/(^|\.)vercel-dns\.com$/',
        };
    }

    public static function forNameserver(string $host): ?self
    {
        $host = rtrim(strtolower(trim($host)), '.');
        foreach (self::cases() as $case) {
            if (preg_match($case->pattern(), $host)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Anbieter einer Zone anhand all ihrer Nameserver.
     *
     * @param  list<string>  $nameservers
     * @return list<self> erkannte Anbieter (mehrere bei Multi-Provider-Setups)
     */
    public static function detect(array $nameservers): array
    {
        $found = [];
        foreach ($nameservers as $host) {
            if ($provider = self::forNameserver($host)) {
                $found[$provider->value] = $provider;
            }
        }

        return array_values($found);
    }

    /** Vorschläge für die Eingabefelder (Registrar und DNS-Verwaltung). @return list<string> */
    public static function labels(): array
    {
        return array_map(fn (self $p) => $p->label(), self::cases());
    }
}
