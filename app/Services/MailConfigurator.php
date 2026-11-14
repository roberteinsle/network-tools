<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Baut zur Laufzeit einen Mailer aus den im Backend gespeicherten SMTP-Daten.
 * Ohne SMTP-Konfiguration wird der Standard-Mailer aus .env verwendet (lokal: log).
 */
class MailConfigurator
{
    public function mailer(?AppSetting $settings = null): Mailer
    {
        $s = $settings ?? AppSetting::current();

        if (! $s->smtpConfigured()) {
            return Mail::mailer();
        }

        config([
            'mail.mailers.backend_smtp' => [
                'transport' => 'smtp',
                'scheme' => $s->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $s->smtp_host,
                'port' => $s->smtp_port ?: ($s->smtp_encryption === 'ssl' ? 465 : 587),
                'username' => $s->smtp_username,
                'password' => $s->smtp_password,
                'timeout' => 20,
                'auto_tls' => $s->smtp_encryption !== 'none',
            ],
        ]);

        // Worker laufen lange: geänderte Zugangsdaten sofort übernehmen
        app('mail.manager')->purge('backend_smtp');

        $mailer = Mail::mailer('backend_smtp');
        $mailer->alwaysFrom($s->mail_from_address, $s->mail_from_name ?: config('app.name'));

        return $mailer;
    }
}
