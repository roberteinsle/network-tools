<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Registry der Werkzeuge im Backend (Menügruppen Werkzeuge, Mail-Sicherheit,
 * Reverse-DNS, Fehlersuche). Jedes Werkzeug läuft über App\Services\Lookup\ToolRunner:
 * Record-Abfragen über die Lookup-Services, Mail-Sicherheit über die registrierten Checks.
 * Neue Werkzeuge nur hier eintragen (und im ToolRunner zuordnen).
 */
enum DnsTool: string
{
    // Werkzeuge
    case Mx = 'mx';
    case Ip = 'ip';
    case Nameserver = 'ns';
    case Soa = 'soa';
    case Cname = 'cname';
    case Txt = 'txt';
    case Caa = 'caa';
    // Mail-Sicherheit
    case Spf = 'spf';
    case Dmarc = 'dmarc';
    case Dkim = 'dkim';
    case MtaSts = 'mta-sts';
    case TlsRpt = 'tls-rpt';
    case Dane = 'dane';
    // Reverse-DNS
    case Ptr = 'ptr';
    // Fehlersuche
    case Trace = 'trace';
    case Dnssec = 'dnssec';
    case Ds = 'ds';

    public const GROUP_TOOLS = 'Werkzeuge';

    public const GROUP_MAIL = 'Mail-Sicherheit';

    public const GROUP_REVERSE = 'Reverse-DNS';

    public const GROUP_DEBUG = 'Fehlersuche';

    public function label(): string
    {
        return match ($this) {
            self::Mx => 'MX-Lookup',
            self::Ip => 'IP',
            self::Nameserver => 'Nameserver',
            self::Soa => 'SOA',
            self::Cname => 'CNAME',
            self::Txt => 'TXT',
            self::Caa => 'CAA',
            self::Spf => 'SPF',
            self::Dmarc => 'DMARC',
            self::Dkim => 'DKIM',
            self::MtaSts => 'MTA-STS',
            self::TlsRpt => 'TLS-RPT',
            self::Dane => 'DANE',
            self::Ptr => 'PTR',
            self::Trace => 'Trace',
            self::Dnssec => 'DNSSEC',
            self::Ds => 'DS',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mx => 'Mailserver einer Domain inklusive Priorität und Adressen.',
            self::Ip => 'IPv4- und IPv6-Adressen laut autoritativen Nameservern und den zehn verbreitetsten öffentlichen Resolvern.',
            self::Nameserver => 'Zuständige Nameserver der Zone mit Adressen und SOA-Serial je Server.',
            self::Soa => 'Zonen-Informationen: primärer Nameserver, Admin, Serial und TTLs.',
            self::Cname => 'Alias-Ziel eines Namens und die komplette CNAME-Kette.',
            self::Txt => 'Text-Records wie SPF, DMARC und Verifizierungen.',
            self::Caa => 'Welche Zertifizierungsstellen Zertifikate für die Domain ausstellen dürfen.',
            self::Spf => 'SPF-Record mit Bewertung und Zähler für das 10-DNS-Lookup-Limit.',
            self::Dmarc => 'DMARC-Richtlinie, Reports und Autorisierung externer Report-Empfänger.',
            self::Dkim => 'DKIM-Schlüssel der angegebenen Selektoren (DKIM-Selektoren lassen sich nicht aus dem DNS auslesen, z. B. M365: selector1/selector2).',
            self::MtaSts => 'MTA-STS-Record und Policy mit Abgleich der MX-Hosts.',
            self::TlsRpt => 'TLS-RPT-Record und Report-Ziele.',
            self::Dane => 'TLSA-Records der MX-Hosts (DANE) mit DNSSEC-Prüfung.',
            self::Ptr => 'Reverse DNS einer IP-Adresse (oder aller Adressen eines Hostnamens) mit Vorwärts-Bestätigung.',
            self::Trace => 'Auflösung von den Root-Servern abwärts – zeigt, auf welcher Ebene sie hakt.',
            self::Dnssec => 'DNSSEC-Signaturen (RRSIG) eines Records mit Gültigkeit.',
            self::Ds => 'DS-Record in der Parent-Zone im Abgleich mit den DNSKEYs der Zone.',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::Spf, self::Dmarc, self::Dkim, self::MtaSts, self::TlsRpt, self::Dane => self::GROUP_MAIL,
            self::Ptr => self::GROUP_REVERSE,
            self::Trace, self::Dnssec, self::Ds => self::GROUP_DEBUG,
            default => self::GROUP_TOOLS,
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Mx => Heroicon::OutlinedInboxStack,
            self::Ip => Heroicon::OutlinedMagnifyingGlass,
            self::Nameserver => Heroicon::OutlinedServerStack,
            self::Soa => Heroicon::OutlinedInformationCircle,
            self::Cname => Heroicon::OutlinedArrowUturnRight,
            self::Txt => Heroicon::OutlinedDocumentText,
            self::Caa => Heroicon::OutlinedCheckBadge,
            self::Spf => Heroicon::OutlinedPaperAirplane,
            self::Dmarc => Heroicon::OutlinedShieldCheck,
            self::Dkim => Heroicon::OutlinedKey,
            self::MtaSts => Heroicon::OutlinedLockClosed,
            self::TlsRpt => Heroicon::OutlinedChartBar,
            self::Dane => Heroicon::OutlinedFingerPrint,
            self::Ptr => Heroicon::OutlinedArrowsRightLeft,
            self::Trace => Heroicon::OutlinedMap,
            self::Dnssec => Heroicon::OutlinedPencilSquare,
            self::Ds => Heroicon::OutlinedLink,
        };
    }

    /** Für Werkzeuge der Mail-Sicherheit: der registrierte Check, der ausgeführt wird. */
    public function checkType(): ?CheckType
    {
        return match ($this) {
            self::Spf => CheckType::Spf,
            self::Dmarc => CheckType::Dmarc,
            self::Dkim => CheckType::Dkim,
            self::MtaSts => CheckType::MtaSts,
            self::TlsRpt => CheckType::TlsRpt,
            self::Dane => CheckType::Dane,
            default => null,
        };
    }

    /** Art der Eingabe: Hostname, Domain oder IP/Hostname. */
    public function inputLabel(): string
    {
        return match ($this) {
            self::Ptr => 'IP-Adresse oder Hostname',
            self::Ip, self::Cname, self::Txt, self::Caa, self::Trace, self::Dnssec => 'Hostname',
            default => 'Domain',
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::Ptr => '192.0.2.10',
            self::Ip, self::Cname, self::Trace, self::Dnssec => 'www.beispiel.de',
            default => 'beispiel.de',
        };
    }

    public function sort(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** @return list<self> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->group() === $group));
    }
}
