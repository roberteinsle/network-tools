<?php

namespace App\Enums;

/**
 * Registry der bekannten SRV-Dienste (RFC 2782), die der SRV-Check an jeder Domain sucht.
 * Fehlt ein bekannter Dienst, ist das kein Befund (nicht jede Domain bietet ihn an);
 * erwartete Dienste pflegt man je Domain im Feld „Erwartete SRV-Dienste“.
 * Neue Dienste nur hier eintragen.
 */
enum SrvService: string
{
    case Submission = '_submission._tcp';
    case Submissions = '_submissions._tcp';
    case Imap = '_imap._tcp';
    case Imaps = '_imaps._tcp';
    case Pop3 = '_pop3._tcp';
    case Pop3s = '_pop3s._tcp';
    case Autodiscover = '_autodiscover._tcp';
    case Sip = '_sip._tls';
    case SipFederation = '_sipfederationtls._tcp';
    case Caldavs = '_caldavs._tcp';
    case Carddavs = '_carddavs._tcp';
    case XmppClient = '_xmpp-client._tcp';
    case XmppServer = '_xmpp-server._tcp';

    public function label(): string
    {
        return match ($this) {
            self::Submission => 'Mail-Versand (Submission, STARTTLS)',
            self::Submissions => 'Mail-Versand (Submission, TLS)',
            self::Imap => 'IMAP (STARTTLS)',
            self::Imaps => 'IMAP (TLS)',
            self::Pop3 => 'POP3 (STARTTLS)',
            self::Pop3s => 'POP3 (TLS)',
            self::Autodiscover => 'Exchange Autodiscover',
            self::Sip => 'SIP / Skype for Business',
            self::SipFederation => 'SIP-Föderation (Skype for Business)',
            self::Caldavs => 'CalDAV (Kalender)',
            self::Carddavs => 'CardDAV (Kontakte)',
            self::XmppClient => 'XMPP-Client',
            self::XmppServer => 'XMPP-Server',
        };
    }

    /** Üblicher Port des Dienstes (andere Ports sind erlaubt, werden aber als Hinweis genannt). */
    public function port(): int
    {
        return match ($this) {
            self::Submission => 587,
            self::Submissions => 465,
            self::Imap => 143,
            self::Imaps => 993,
            self::Pop3 => 110,
            self::Pop3s => 995,
            self::Autodiscover, self::Sip, self::Caldavs, self::Carddavs => 443,
            self::SipFederation => 5061,
            self::XmppClient => 5222,
            self::XmppServer => 5269,
        };
    }

    /** Name eines SRV-Dienstes: _dienst._protokoll */
    public const NAME_PATTERN = '/^_[a-z0-9][a-z0-9-]{0,61}\._[a-z0-9][a-z0-9-]{0,61}$/';

    /** Bezeichnung für einen beliebigen Dienstnamen (bekannt oder eigen). */
    public static function labelFor(string $service): string
    {
        return self::tryFrom($service)?->label() ?? $service;
    }
}
