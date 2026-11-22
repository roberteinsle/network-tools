<?php

namespace App\Filament\Pages;

use App\Enums\CheckStatus;
use App\Enums\DkimProvider;
use App\Enums\DnsTool;
use App\Enums\SrvService;
use App\Filament\Support\ResultSchema;
use App\Models\CheckResult;
use App\Models\CheckRun;
use App\Services\Lookup\DnssecLookup;
use App\Services\Lookup\ToolRunner;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Eine Seite für alle Werkzeuge der DnsTool-Registry (/admin/tools/{tool}).
 * Die Menüeinträge erzeugt der AdminPanelProvider aus der Registry. Speichert nichts.
 *
 * @property-read Schema $form
 * @property-read Schema $resultInfolist
 */
class Tool extends Page
{
    protected static ?string $slug = 'tools/{tool}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.tool';

    #[Locked]
    public string $tool = '';

    public ?array $data = [];

    public ?array $result = null;

    public ?string $queried = null;

    public function mount(string $tool): void
    {
        abort_unless(DnsTool::tryFrom($tool) !== null, 404);
        $this->tool = $tool;
        $this->form->fill([
            'name' => request()->query('q'),
            'record_type' => 'A',
            'check_mx_tls' => false,
        ]);
    }

    public function getTool(): DnsTool
    {
        return DnsTool::from($this->tool);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getTool()->label();
    }

    public function getSubheading(): ?string
    {
        return $this->getTool()->description();
    }

    public function form(Schema $schema): Schema
    {
        $tool = $this->getTool();
        $hostPattern = '/^(?=.{1,253}$)((?!-)[\p{L}\p{N}_-]{1,63}(?<!-)\.)+[\p{L}\p{N}-]{2,63}\.?$/u';

        $name = TextInput::make('name')
            ->label($tool->inputLabel())
            ->placeholder($tool->placeholder())
            ->required()
            ->maxLength(253)
            ->autofocus();

        $name = $tool === DnsTool::Ptr
            ? $name->rule(fn () => function (string $attribute, $value, $fail) use ($hostPattern) {
                if (! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match($hostPattern, (string) $value)) {
                    $fail('Bitte eine IPv4-/IPv6-Adresse oder einen Hostnamen eingeben.');
                }
            })
            : $name->regex($hostPattern)->validationMessages(['regex' => 'Bitte einen gültigen Namen ohne http:// und ohne Pfad eingeben.']);

        $extra = match ($tool) {
            DnsTool::Dkim => [
                CheckboxList::make('dkim_providers')
                    ->label('Anbieter (bekannte Selektoren)')
                    ->options(DkimProvider::class)
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3]),
                TagsInput::make('dkim_selectors')
                    ->label('Eigene Selektoren')
                    ->placeholder('z. B. selector1')
                    ->helperText('Mindestens einen Anbieter oder Selektor angeben.')
                    ->nestedRecursiveRules(['max:63', 'regex:/^[A-Za-z0-9._-]+$/']),
            ],
            DnsTool::Dane => [
                Toggle::make('check_mx_tls')
                    ->label('Zertifikate per STARTTLS abgleichen (Port 25)')
                    ->helperText('Nur sinnvoll, wenn der Server ausgehenden Port 25 erlaubt.'),
            ],
            DnsTool::Srv => [
                TagsInput::make('srv_services')
                    ->label('Zusätzlich erwartete Dienste')
                    ->placeholder('z. B. _minecraft._tcp')
                    ->helperText('Bekannte Dienste werden immer abgefragt. Hier eingetragene Dienste müssen vorhanden sein, sonst gibt es eine Warnung.')
                    ->nestedRecursiveRules(['max:127', 'regex:'.SrvService::NAME_PATTERN]),
            ],
            DnsTool::Dnssec => [
                Select::make('record_type')
                    ->label('Record-Typ')
                    ->options(array_combine(DnssecLookup::TYPES, DnssecLookup::TYPES))
                    ->in(DnssecLookup::TYPES)
                    ->required(),
            ],
            default => [],
        };

        return $schema->statePath('data')->components([$name, ...$extra]);
    }

    public function lookup(ToolRunner $runner): void
    {
        $data = $this->form->getState();
        $name = rtrim(mb_strtolower(trim($data['name'])), '.');
        if (! filter_var($name, FILTER_VALIDATE_IP) && preg_match('/[^\x20-\x7e]/', $name)) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        if ($this->getTool() === DnsTool::Dkim && empty($data['dkim_providers']) && empty($data['dkim_selectors'])) {
            $this->addError('data.dkim_selectors', 'Mindestens einen Anbieter oder Selektor angeben.');

            return;
        }

        @set_time_limit(120);
        $this->queried = $name;
        $this->result = $runner->run($this->getTool(), [...$data, 'name' => $name]);
    }

    public function resultInfolist(Schema $schema): Schema
    {
        $result = $this->result ?? ['type' => 'tables', 'sections' => []];

        if ($result['type'] === 'checks') {
            $results = collect($result['results'])->map(fn (array $r) => new CheckResult($r));
            $run = (new CheckRun(['status' => CheckStatus::worst($results->map(fn (CheckResult $r) => $r->status))]))
                ->setRelation('results', $results);

            return $schema->record($run)->components([
                Section::make("Ergebnis für {$this->queried}")->schema([ResultSchema::results('results')]),
            ]);
        }

        $state = [];
        $components = [];
        foreach ($result['sections'] as $i => $section) {
            $state["s{$i}"] = array_map(
                fn (array $row) => array_combine(array_map(fn ($k) => "c{$k}", array_keys($row)), $row),
                $section['rows'],
            );
            $components[] = Section::make($section['heading'])
                ->description($section['description'])
                ->schema([
                    $section['rows'] === []
                        ? Text::make($section['empty'])->color('gray')
                        : self::table("s{$i}", $section['columns']),
                ]);
        }

        return $schema->state($state)->components($components);
    }

    private static function table(string $path, array $columns): RepeatableEntry
    {
        return RepeatableEntry::make($path)
            ->hiddenLabel()
            ->table(array_map(fn (array $c) => TableColumn::make($c['label']), $columns))
            ->schema(array_map(function (array $c, int $i) {
                $entry = TextEntry::make("c{$i}")->placeholder('–')->listWithLineBreaks();
                if ($c['badge']) {
                    $entry->badge()
                        ->formatStateUsing(fn (?string $state) => CheckStatus::tryFrom((string) $state)?->getLabel() ?? $state)
                        ->color(fn (?string $state) => CheckStatus::tryFrom((string) $state)?->getColor() ?? 'gray')
                        ->icon(fn (?string $state) => CheckStatus::tryFrom((string) $state)?->getIcon());
                }
                if ($c['mono']) {
                    $entry->fontFamily('mono')->copyable()->extraAttributes(['style' => 'overflow-wrap: anywhere']);
                }

                return $entry;
            }, $columns, array_keys($columns)));
    }
}
