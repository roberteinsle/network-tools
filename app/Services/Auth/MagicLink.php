<?php

namespace App\Services\Auth;

use App\Mail\MagicLinkMail;
use App\Models\MagicLoginToken;
use App\Models\User;
use App\Services\MailConfigurator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Magic Link nach Standard: max. 15 Minuten gültig, genau einmal nutzbar,
 * signierte URL; das Token liegt nur als Hash in der Datenbank.
 */
class MagicLink
{
    public const MINUTES = 15;

    public function __construct(private readonly MailConfigurator $mail) {}

    public function createUrl(User $user): string
    {
        $token = Str::random(64);
        MagicLoginToken::query()->where('expires_at', '<', now())->delete();
        MagicLoginToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::MINUTES),
        ]);

        return URL::temporarySignedRoute('magic-login.show', now()->addMinutes(self::MINUTES), ['token' => $token]);
    }

    /** Sendet den Link, falls die Adresse existiert. Antwort nach außen ist immer gleich. */
    public function send(string $email): void
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return;
        }

        try {
            $this->mail->mailer()->to($user->email)->send(new MagicLinkMail($this->createUrl($user)));
        } catch (Throwable $e) {
            Log::error('Magic Link konnte nicht gesendet werden: '.$e->getMessage());
        }
    }

    /** Löst das Token ein (löscht es) und gibt den Benutzer zurück. */
    public function consume(string $token): ?User
    {
        $record = MagicLoginToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return null;
        }

        $deleted = MagicLoginToken::query()->whereKey($record->id)->delete();

        return $deleted === 1 ? $record->user : null;
    }

    public function isValid(string $token): bool
    {
        return MagicLoginToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->exists();
    }
}
