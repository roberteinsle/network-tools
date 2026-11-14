<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\AppSetting;
use App\Services\MailConfigurator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Mail\Message;
use Throwable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Einstellungen';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Einstellungen';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = AppSetting::current();
        $this->form->fill([
            ...$settings->attributesToArray(),
            'smtp_password' => null,
            'spamhaus_dqs_key' => null,
            'has_smtp_password' => filled($settings->smtp_password),
            'has_dqs_key' => filled($settings->spamhaus_dqs_key),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Hidden::make('has_smtp_password')->dehydrated(false),
                Hidden::make('has_dqs_key')->dehydrated(false),
                Tabs::make()->persistTabInQueryString()->tabs([
                    Tab::make('E-Mail-Versand')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->schema([
                            Section::make('SMTP-Server')
                                ->description('Über diesen Server gehen Alarm-Mails und Anmeldelinks raus. Das Passwort wird verschlüsselt gespeichert.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('smtp_host')->label('Host')->placeholder('smtp.beispiel.de')->maxLength(255),
                                    Grid::make(2)->schema([
                                        TextInput::make('smtp_port')->label('Port')->numeric()->minValue(1)->maxValue(65535)->placeholder('587'),
                                        Select::make('smtp_encryption')->label('Verschlüsselung')
                                            ->options(['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS (465)', 'none' => 'keine'])
                                            ->in(['tls', 'ssl', 'none'])
                                            ->required(),
                                    ]),
                                    TextInput::make('smtp_username')->label('Benutzername')->maxLength(255)->autocomplete('off'),
                                    TextInput::make('smtp_password')->label('Passwort')->password()->revealable()->maxLength(500)
                                        ->autocomplete('new-password')
                                        ->placeholder(fn ($get) => $get('has_smtp_password') ? '•••••••• (gespeichert – leer lassen zum Beibehalten)' : null),
                                    TextInput::make('mail_from_address')->label('Absender-Adresse')->email()->maxLength(255)
                                        ->requiredWith('smtp_host'),
                                    TextInput::make('mail_from_name')->label('Absender-Name')->maxLength(255)->placeholder(config('app.name')),
                                ]),
                            Section::make('Benachrichtigungen')
                                ->schema([
                                    TagsInput::make('notification_emails')
                                        ->label('Empfänger für alle Domains')
                                        ->placeholder('name@beispiel.de')
                                        ->helperText('Weitere Empfänger lassen sich je Kunde und je Domain ergänzen.')
                                        ->nestedRecursiveRules(['email:rfc', 'max:255']),
                                    Toggle::make('notify_on_recovery')->label('Auch melden, wenn ein Problem behoben ist'),
                                ]),
                        ]),
                    Tab::make('Prüfungen')
                        ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->schema([
                            Section::make('Schwellwerte')
                                ->columns(['default' => 2, 'md' => 4])
                                ->schema([
                                    TextInput::make('cert_warning_days')->label('Zertifikat: Warnung ab (Tage)')->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                                    TextInput::make('cert_error_days')->label('Zertifikat: Fehler ab (Tage)')->numeric()->integer()->minValue(0)->maxValue(365)->required(),
                                    TextInput::make('domain_warning_days')->label('Domain: Warnung ab (Tage)')->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                                    TextInput::make('domain_error_days')->label('Domain: Fehler ab (Tage)')->numeric()->integer()->minValue(0)->maxValue(365)->required(),
                                ]),
                            Section::make('Zeitplan und Aufbewahrung')
                                ->columns(2)
                                ->schema([
                                    Select::make('default_interval_minutes')->label('Standard-Intervall für neue Domains')
                                        ->options(DomainResource::INTERVALS)
                                        ->in(array_keys(DomainResource::INTERVALS))
                                        ->required(),
                                    TextInput::make('retention_days')->label('Protokolle aufbewahren (Tage)')->numeric()->integer()->minValue(7)->maxValue(3650)->required(),
                                ]),
                            Section::make('DNS')
                                ->columns(2)
                                ->schema([
                                    TagsInput::make('dns_resolvers')
                                        ->label('Resolver für Prüfungen')
                                        ->placeholder('Standard: 1.1.1.1, 8.8.8.8, 9.9.9.9')
                                        ->helperText('Müssen DNSSEC validieren (AD-Flag), sonst ist die DNSSEC-Prüfung ungenau.')
                                        ->nestedRecursiveRules(['ip']),
                                    TagsInput::make('rbl_resolvers')
                                        ->label('Resolver für Blacklists')
                                        ->placeholder('Standard: Resolver des Servers')
                                        ->helperText('Viele RBLs sperren öffentliche Resolver wie 8.8.8.8. Am besten einen eigenen Resolver (z. B. Unbound) verwenden.')
                                        ->nestedRecursiveRules(['ip']),
                                ]),
                            Section::make('Spamhaus Data Query Service (DQS)')
                                ->description('Spamhaus wird nur über DQS abgefragt – die öffentlichen Spamhaus-Server sind für diese Nutzung lizenzwidrig. Kostenloser Schlüssel für geringes Volumen: spamhaus.com → Free Data Query Service.')
                                ->schema([
                                    TextInput::make('spamhaus_dqs_key')->label('DQS-Schlüssel')->password()->revealable()->maxLength(100)
                                        ->regex('/^[a-z0-9]+$/i')
                                        ->placeholder(fn ($get) => $get('has_dqs_key') ? '•••••••• (gespeichert – leer lassen zum Beibehalten)' : 'nicht gesetzt – Spamhaus wird nicht abgefragt'),
                                    Toggle::make('remove_dqs_key')->label('Gespeicherten Schlüssel entfernen')->default(false)
                                        ->visible(fn ($get) => $get('has_dqs_key')),
                                ]),
                        ]),
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = AppSetting::current();

        $values = collect($data)->only($settings->getFillable())->all();
        if (blank($data['smtp_password'] ?? null)) {
            unset($values['smtp_password']);
        }
        if (blank($data['spamhaus_dqs_key'] ?? null)) {
            unset($values['spamhaus_dqs_key']);
        }
        if ($data['remove_dqs_key'] ?? false) {
            $values['spamhaus_dqs_key'] = null;
        }

        $settings->fill($values)->save();
        AppSetting::flush();
        $this->mount();

        Notification::make()->title('Einstellungen gespeichert')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testMail')
                ->label('Testmail senden')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->schema([
                    TextInput::make('to')->label('Empfänger')->email()->required()->maxLength(255)
                        ->default(fn () => auth()->user()?->email),
                ])
                ->modalDescription('Verwendet die gespeicherten SMTP-Einstellungen – vorher speichern.')
                ->action(function (array $data, MailConfigurator $mail) {
                    $settings = AppSetting::current();
                    if (! $settings->smtpConfigured()) {
                        Notification::make()->title('SMTP ist noch nicht eingerichtet')->body('Host und Absender-Adresse speichern.')->warning()->send();

                        return;
                    }
                    try {
                        $mail->mailer($settings)->raw(
                            'Diese Testmail bestätigt, dass der SMTP-Versand von '.config('app.name').' funktioniert.',
                            fn (Message $m) => $m->to($data['to'])->subject('Testmail von '.config('app.name')),
                        );
                        Notification::make()->title('Testmail gesendet')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Versand fehlgeschlagen')->body(mb_strimwidth($e->getMessage(), 0, 300, '…'))->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
