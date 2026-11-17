<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Bekannte DKIM-Selektoren je Anbieter. Anbieter mit zufälligen Selektoren
 * (z. B. Amazon SES, Postmark) werden über eigene Selektoren an der Domain gepflegt.
 */
enum DkimProvider: string implements HasDescription, HasLabel
{
    case Microsoft365 = 'm365';
    case Google = 'google';
    case Mailchimp = 'mailchimp';
    case SendGrid = 'sendgrid';
    case Mailjet = 'mailjet';
    case Brevo = 'brevo';
    case HubSpot = 'hubspot';
    case Zoho = 'zoho';
    case Mailgun = 'mailgun';
    case Ionos = 'ionos';
    case Mailcow = 'mailcow';
    case Generic = 'generic';

    /** @return list<string> */
    public function selectors(): array
    {
        return match ($this) {
            self::Microsoft365 => ['selector1', 'selector2'],
            self::Google => ['google'],
            self::Mailchimp => ['k1', 'k2', 'k3'],
            self::SendGrid => ['s1', 's2'],
            self::Mailjet => ['mailjet'],
            self::Brevo => ['brevo1', 'brevo2', 'mail'],
            self::HubSpot => ['hs1', 'hs2'],
            self::Zoho => ['zmail', 'zoho'],
            self::Mailgun => ['mx', 'smtp', 'mailo'],
            self::Ionos => ['s1-ionos', 's2-ionos'],
            self::Mailcow => ['dkim'],
            self::Generic => ['default', 'dkim', 'mail'],
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Microsoft365 => 'Microsoft 365',
            self::Google => 'Google Workspace',
            self::Mailchimp => 'Mailchimp / Mandrill',
            self::SendGrid => 'SendGrid',
            self::Mailjet => 'Mailjet',
            self::Brevo => 'Brevo',
            self::HubSpot => 'HubSpot',
            self::Zoho => 'Zoho Mail',
            self::Mailgun => 'Mailgun',
            self::Ionos => 'IONOS',
            self::Mailcow => 'Mailcow',
            self::Generic => 'Allgemein (default, dkim, mail)',
        };
    }

    public function getDescription(): string
    {
        return 'Selektoren: '.implode(', ', $this->selectors());
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
