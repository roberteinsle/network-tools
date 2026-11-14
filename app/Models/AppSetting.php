<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Einzeilige Tabelle mit den im Backend gepflegten Einstellungen (SMTP, Empfänger, Schwellwerte).
 * Passwort und Spamhaus-DQS-Schlüssel liegen verschlüsselt (APP_KEY) in der Datenbank.
 */
class AppSetting extends Model
{
    protected $fillable = [
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'mail_from_address', 'mail_from_name', 'notification_emails', 'notify_on_recovery',
        'dns_resolvers', 'rbl_resolvers', 'spamhaus_dqs_key',
        'cert_warning_days', 'cert_error_days', 'domain_warning_days', 'domain_error_days',
        'default_interval_minutes', 'retention_days',
    ];

    protected $hidden = ['smtp_password', 'spamhaus_dqs_key'];

    private static ?self $cached = null;

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'spamhaus_dqs_key' => 'encrypted',
            'notification_emails' => 'array',
            'dns_resolvers' => 'array',
            'rbl_resolvers' => 'array',
            'notify_on_recovery' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$cached = null);
    }

    public static function current(): self
    {
        if (self::$cached === null) {
            $settings = self::query()->first();
            if ($settings === null) {
                $settings = self::query()->create(['smtp_encryption' => 'tls']);
                $settings->refresh(); // Datenbank-Standardwerte übernehmen
            }
            self::$cached = $settings;
        }

        return self::$cached;
    }

    public static function flush(): void
    {
        self::$cached = null;
    }

    public function smtpConfigured(): bool
    {
        return filled($this->smtp_host) && filled($this->mail_from_address);
    }

    /** @return list<string> */
    public function dnsResolvers(): array
    {
        $list = array_values(array_filter($this->dns_resolvers ?? []));

        return $list !== [] ? $list : ['1.1.1.1', '8.8.8.8', '9.9.9.9'];
    }

    /** Leer = Resolver des Systems (öffentliche Resolver werden von vielen RBLs geblockt). */
    public function rblResolvers(): array|string
    {
        $list = array_values(array_filter($this->rbl_resolvers ?? []));

        return $list !== [] ? $list : '/etc/resolv.conf';
    }
}
