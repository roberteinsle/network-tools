<?php

namespace App\Enums;

/**
 * Registry der öffentlichen DNS-Resolver für den DNS-Lookup (die zehn verbreitetsten
 * frei nutzbaren Dienste). Neue Resolver nur hier eintragen.
 */
enum PublicResolver: string
{
    case Google = 'google';
    case Cloudflare = 'cloudflare';
    case Quad9 = 'quad9';
    case OpenDns = 'opendns';
    case AdGuard = 'adguard';
    case CleanBrowsing = 'cleanbrowsing';
    case ControlD = 'controld';
    case Comodo = 'comodo';
    case Level3 = 'level3';
    case Yandex = 'yandex';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Public DNS',
            self::Cloudflare => 'Cloudflare',
            self::Quad9 => 'Quad9',
            self::OpenDns => 'Cisco OpenDNS',
            self::AdGuard => 'AdGuard DNS',
            self::CleanBrowsing => 'CleanBrowsing',
            self::ControlD => 'Control D',
            self::Comodo => 'Comodo Secure DNS',
            self::Level3 => 'Lumen (Level 3)',
            self::Yandex => 'Yandex DNS',
        };
    }

    public function ip(): string
    {
        return match ($this) {
            self::Google => '8.8.8.8',
            self::Cloudflare => '1.1.1.1',
            self::Quad9 => '9.9.9.9',
            self::OpenDns => '208.67.222.222',
            self::AdGuard => '94.140.14.140', // ungefilterte Variante
            self::CleanBrowsing => '185.228.168.10', // Security-Filter, blockt nur Schadcode-Domains
            self::ControlD => '76.76.2.0', // ungefilterte Variante
            self::Comodo => '8.26.56.26',
            self::Level3 => '4.2.2.1',
            self::Yandex => '77.88.8.8',
        };
    }
}
