<?php

namespace App\Filament\Pages\Auth;

use App\Services\Auth\MagicLink;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Anmeldung nach Standard: 1. Passkey, 2. Magic Link per E-Mail. Keine Passwörter.
 */
class Login extends BaseLogin
{
    public bool $linkSent = false;

    public function mount(): void
    {
        parent::mount();

        if (session('magic_error')) {
            Notification::make()
                ->title('Der Anmeldelink ist abgelaufen oder wurde bereits verwendet.')
                ->danger()
                ->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.auth.passkey-login'),
            $this->getEmailFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('E-Mail-Adresse')
            ->email()
            ->required()
            ->maxLength(255)
            ->autocomplete('username webauthn');
    }

    /** Sendet einen Magic Link. Die Antwort ist immer gleich, egal ob die Adresse existiert. */
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $email = strtolower(trim($this->form->getState()['email']));
        app(MagicLink::class)->send($email);

        Notification::make()
            ->title('Prüfe dein Postfach')
            ->body('Wenn ein Konto mit dieser Adresse existiert, haben wir einen Anmeldelink geschickt. Er ist '.MagicLink::MINUTES.' Minuten gültig.')
            ->success()
            ->persistent()
            ->send();

        $this->linkSent = true;

        return null;
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label('Anmeldelink per E-Mail senden')
            ->color('gray')
            ->submit('authenticate');
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Anmelden';
    }
}
