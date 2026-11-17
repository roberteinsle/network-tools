<?php

namespace App\Filament\Pages;

use App\Checks\CheckOutcome;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DkimProvider;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\ResultSchema;
use App\Models\CheckResult;
use App\Models\CheckRun;
use App\Models\Domain;
use App\Services\DomainChecker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Einmalige Prüfung einer beliebigen Domain, ohne sie anzulegen. Nutzt dieselben
 * registrierten Prüfungen wie die Überwachung, speichert aber nichts und meldet nichts.
 *
 * @property-read Schema $form
 * @property-read Schema $resultInfolist
 */
class DnsCheck extends Page
{
    /** Voreinstellung: reine DNS-Prüfungen, schnell und ohne Verbindungen zu Web- oder Mailservern. */
    public const DNS_CHECKS = [CheckType::Ns, CheckType::Dnssec, CheckType::Caa, CheckType::Mx, CheckType::Address, CheckType::Ptr, CheckType::Spf, CheckType::Dmarc];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Werkzeuge';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'DNS-Check';

    protected static ?string $slug = 'dns-check';

    protected string $view = 'filament.pages.dns-check';

    public ?array $data = [];

    /** Ergebnisse des letzten Laufs als Arrays (Livewire-tauglich). */
    public array $results = [];

    public ?string $checkedName = null;

    public ?int $durationMs = null;

    public function mount(): void
    {
        $this->form->fill([
            'name' => request()->query('domain'),
            'checks' => array_map(fn (CheckType $t) => $t->value, self::DNS_CHECKS),
            'check_mx_tls' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description('Prüft eine beliebige Domain sofort mit denselben Regeln wie die Überwachung. Es wird nichts gespeichert und keine E-Mail verschickt.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Domainname')
                            ->placeholder('beispiel.de')
                            ->required()
                            ->maxLength(253)
                            ->regex('/^(?=.{1,253}$)((?!-)[\p{L}\p{N}-]{1,63}(?<!-)\.)+[\p{L}\p{N}-]{2,63}$/u')
                            ->validationMessages(['regex' => 'Bitte einen gültigen Domainnamen ohne http:// und ohne Pfad eingeben.'])
                            ->autofocus(),
                        CheckboxList::make('checks')
                            ->label('Prüfungen')
                            ->options(CheckType::options())
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                            ->bulkToggleable()
                            ->required()
                            ->helperText('Voreingestellt sind die DNS-Prüfungen. Zertifikate, HTTP und Blacklists dauern länger.'),
                    ]),
                Section::make('Optionen')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('dkim_providers')
                            ->label('DKIM-Anbieter')
                            ->options(DkimProvider::class)
                            ->columns(2),
                        TagsInput::make('dkim_selectors')
                            ->label('Eigene DKIM-Selektoren')
                            ->placeholder('z. B. s2024')
                            ->nestedRecursiveRules(['max:63', 'regex:/^[A-Za-z0-9._-]+$/']),
                        TagsInput::make('web_hosts')
                            ->label('Web-Hosts')
                            ->placeholder('leer = Domain und www.')
                            ->nestedRecursiveRules(['max:253', 'regex:/^[A-Za-z0-9.-]+$/']),
                        Toggle::make('check_mx_tls')
                            ->label('Port 25 verwenden (STARTTLS-Zertifikate, DANE-Abgleich)')
                            ->helperText('Nur sinnvoll, wenn der Server ausgehenden Port 25 erlaubt.'),
                    ]),
            ]);
    }

    public function check(DomainChecker $checker): void
    {
        $data = $this->form->getState();
        $types = array_values(array_filter(array_map(fn ($v) => CheckType::tryFrom((string) $v), $data['checks'] ?? [])));

        $domain = new Domain([
            'name' => Domain::normalizeName($data['name']),
            'inherit_dkim_providers' => false,
            'dkim_providers' => $data['dkim_providers'] ?? [],
            'dkim_selectors' => $data['dkim_selectors'] ?? [],
            'web_hosts' => $data['web_hosts'] ?? [],
            'check_mx_tls' => (bool) ($data['check_mx_tls'] ?? false),
        ]);

        @set_time_limit(180);
        $t0 = hrtime(true);
        $outcomes = $checker->evaluate($domain, $types);

        $this->durationMs = (int) ((hrtime(true) - $t0) / 1e6);
        $this->checkedName = $domain->name;
        $this->results = array_map(fn (string $check, CheckOutcome $o) => [
            'check' => $check,
            'status' => $o->status()->value,
            'summary' => mb_strimwidth($o->getSummary(), 0, 500, '…'),
            'details' => $o->details(),
        ], array_keys($outcomes), $outcomes);
    }

    /** Ungespeicherte Modelle, damit die Darstellung der Domain-Seite wiederverwendet wird. */
    private function run(): CheckRun
    {
        $results = collect($this->results)->map(fn (array $r) => new CheckResult($r));
        $run = new CheckRun(['status' => CheckStatus::worst($results->map(fn (CheckResult $r) => $r->status))]);

        return $run->setRelation('results', $results);
    }

    public function resultInfolist(Schema $schema): Schema
    {
        $run = $this->run();
        $problems = collect($this->results)->filter(fn ($r) => CheckStatus::from($r['status'])->isProblem())->count();

        return $schema
            ->record($run)
            ->components([
                Section::make("Ergebnis für {$this->checkedName}")
                    ->description($run->status->getLabel().' · '.count($this->results).' Prüfung(en), '.$problems.' mit Problemen · '.$this->durationMs.' ms')
                    ->headerActions([
                        Action::make('monitor')
                            ->label('Überwachen')
                            ->icon(Heroicon::OutlinedPlus)
                            ->color('gray')
                            ->visible(fn () => ! Domain::query()->where('name', $this->checkedName)->exists())
                            ->url(fn () => DomainResource::getUrl('create', ['name' => $this->checkedName])),
                    ])
                    ->schema([ResultSchema::results('results')]),
            ]);
    }
}
