<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Zentrale Cloudflare-Erkennung: Proxy-IP-Bereiche (https://www.cloudflare.com/ips/)
 * und Cloudflare-Nameserver. Bereiche nur hier pflegen.
 */
final class Cloudflare
{
    public const PROXY_RANGES = [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function isProxyIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false && IpUtils::checkIp($ip, self::PROXY_RANGES);
    }

    /** Alle Adressen sind Cloudflare-Proxy-IPs (leere Liste = nein). @param  list<string>  $ips */
    public static function allProxyIps(array $ips): bool
    {
        return $ips !== [] && array_filter($ips, fn ($ip) => ! self::isProxyIp($ip)) === [];
    }

    public static function isNameserver(string $host): bool
    {
        return str_ends_with(rtrim(strtolower($host), '.'), '.ns.cloudflare.com');
    }
}
