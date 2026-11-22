<?php

namespace App\Filament\Pages;

use App\Services\Lookup\DnsLookup as DnsLookupService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * DNS-Lookup: zeigt die IPs eines Hostnamens laut autoritativen Nameservern
 * und laut den zehn verbreitetsten öffentlichen Resolvern. Speichert nichts.
 *
 * @property-read Schema $form
 * @property-read Schema $resultInfolist
 */
class DnsLookup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Werkzeuge';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'DNS-Lookup';

    protected static ?string $slug = 'dns-lookup';

    protected string $view = 'filament.pages.dns-lookup';

    private const NOWRAP = ['style' => 'white-space: nowrap'];

    public ?array $data = [];

    public ?array $result = null;

    public ?string $host = null;

    public function mount(): void
    {
        $this->form->fill(['name' => request()->query('host')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('name')
                    ->label('Hostname')
                    ->placeholder('www.beispiel.de')
                    ->required()
                    ->maxLength(253)
                    ->regex('/^(?=.{1,253}$)((?!-)[\p{L}\p{N}_-]{1,63}(?<!-)\.)+[\p{L}\p{N}-]{2,63}\.?$/u')
                    ->validationMessages(['regex' => 'Bitte einen gültigen Hostnamen ohne http:// und ohne Pfad eingeben.'])
                    ->autofocus(),
            ]);
    }

    public function lookup(DnsLookupService $lookup): void
    {
        $name = rtrim(mb_strtolower(trim($this->form->getState()['name'])), '.');
        if (preg_match('/[^\x20-\x7e]/', $name)) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        @set_time_limit(120);
        $this->host = $name;
        $this->result = $lookup->lookup($name);
    }

    /** Zeilen für die Tabellen (Livewire-taugliche Arrays). */
    private static function rows(array $rows): array
    {
        return array_map(fn (array $r) => [
            // Servername mit der abgefragten Adresse klein darunter (beides escaped)
            'server' => e($r['server']).'<br><span style="font-family: ui-monospace, monospace; font-size: 0.75rem; color: var(--gray-500)">'.e($r['ip']).'</span>',
            'a' => $r['a'] ?: ['–'],
            'aaaa' => $r['aaaa'] ?: ['–'],
            'note' => $r['error'] ?? ($r['cname'] ? 'CNAME → '.$r['cname'] : null),
        ], $rows);
    }

    private static function table(string $name, string $serverLabel): RepeatableEntry
    {
        return RepeatableEntry::make($name)
            ->hiddenLabel()
            ->table([
                TableColumn::make($serverLabel),
                TableColumn::make('IPv4 (A)'),
                TableColumn::make('IPv6 (AAAA)'),
                TableColumn::make('Hinweis'),
            ])
            ->schema([
                TextEntry::make('server')->weight('medium')->html(),
                TextEntry::make('a')->fontFamily('mono')->listWithLineBreaks()->copyable()->extraAttributes(self::NOWRAP),
                TextEntry::make('aaaa')->fontFamily('mono')->listWithLineBreaks()->copyable()->extraAttributes(self::NOWRAP),
                TextEntry::make('note')->placeholder('–')->color('gray'),
            ]);
    }

    public function resultInfolist(Schema $schema): Schema
    {
        $result = $this->result ?? ['zone' => null, 'authoritative' => [], 'public' => []];

        return $schema
            ->state([
                'authoritative' => self::rows($result['authoritative']),
                'public' => self::rows($result['public']),
            ])
            ->components([
                Section::make('Autoritative Nameserver')
                    ->description($result['zone']
                        ? "Zone {$result['zone']} – Antwort direkt von den Nameservern der Domain"
                        : 'Keine Zone mit Nameservern gefunden.')
                    ->schema([self::table('authoritative', 'Nameserver')]),
                Section::make('Öffentliche DNS-Resolver')
                    ->description('Antwort der zehn verbreitetsten öffentlichen Resolver (inkl. Cache)')
                    ->schema([self::table('public', 'Anbieter')]),
            ]);
    }
}
