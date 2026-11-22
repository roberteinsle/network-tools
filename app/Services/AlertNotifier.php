<?php

namespace App\Services;

use App\Mail\DomainAlertMail;
use App\Models\CheckRun;
use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlertNotifier
{
    public function __construct(private readonly MailConfigurator $mail) {}

    public function send(Domain $domain, CheckRun $run, array $changes, bool $initial): bool
    {
        $recipients = $domain->recipientsFor($changes, $run, $initial);
        if ($recipients === []) {
            Log::info("Keine Empfänger für {$domain->name} – Benachrichtigung übersprungen.");

            return false;
        }

        try {
            $this->mail->mailer()->to($recipients)->send(new DomainAlertMail($domain, $run, $changes, $initial));
        } catch (Throwable $e) {
            Log::error("Alarm-Mail für {$domain->name} fehlgeschlagen: {$e->getMessage()}");

            return false;
        }

        return true;
    }
}
