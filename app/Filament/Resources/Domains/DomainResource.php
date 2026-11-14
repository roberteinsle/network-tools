<?php

namespace App\Filament\Resources\Domains;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\DkimProvider;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Domains\RelationManagers\CheckRunsRelationManager;
use App\Filament\Support\ResultSchema;
use App\Jobs\CheckDomainJob;
use App\Models\AppSetting;
use App\Models\Domain;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Überwachung';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Domain';

    protected static ?string $pluralModelLabel = 'Domains';

    protected static ?string $recordTitleAttribute = 'name';

    public const INTERVALS = [
        60 => 'stündlich',
        180 => 'alle 3 Stunden',
        360 => 'alle 6 Stunden',
        720 => 'alle 12 Stunden',
        1440 => 'täglich',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Domain')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Domainname')
                        ->placeholder('beispiel.de')
                        ->required()
                        ->maxLength(253)
                        ->regex('/^(?=.{1,253}$)((?!-)[\p{L}\p{N}-]{1,63}(?<!-)\.)+[\p{L}\p{N}-]{2,63}$/u')
                        ->validationMessages(['regex' => 'Bitte einen gültigen Domainnamen ohne http:// und ohne Pfad eingeben.'])
                        ->dehydrateStateUsing(fn (?string $state) => $state ? Domain::normalizeName($state) : $state)
                        ->unique(ignoreRecord: true),
                    Select::make('customer_id')
                        ->label('Kunde')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->createOptionForm([
                            TextInput::make('name')->label('Name')->required()->maxLength(255),
                        ]),
                    Toggle::make('active')
                        ->label('Aktiv (wird automatisch geprüft)')
                        ->default(true),
                    Select::make('check_interval_minutes')
                        ->label('Prüfintervall')
                        ->options(self::INTERVALS)
                        ->default(fn () => AppSetting::current()->default_interval_minutes)
                        ->required()
                        ->in(array_keys(self::INTERVALS)),
                ]),

            Section::make('DKIM')
                ->description('Bekannte Selektoren je Anbieter werden automatisch geprüft. Standard: Anbieter des Kunden.')
                ->columns(2)
                ->schema([
                    Toggle::make('inherit_dkim_providers')
                        ->label('Anbieter vom Kunden übernehmen')
                        ->default(true)
                        ->live()
                        ->columnSpanFull(),
                    CheckboxList::make('dkim_providers')
                        ->label('Anbieter')
                        ->options(DkimProvider::class)
                        ->columns(2)
                        ->hidden(fn (Get $get) => (bool) $get('inherit_dkim_providers'))
                        ->columnSpanFull(),
                    TagsInput::make('dkim_selectors')
                        ->label('Eigene Selektoren')
                        ->placeholder('z. B. s2024')
                        ->helperText('Für Anbieter mit individuellen Selektoren (Amazon SES, Postmark, eigener Mailserver).')
                        ->nestedRecursiveRules(['max:63', 'regex:/^[A-Za-z0-9._-]+$/'])
                        ->columnSpanFull(),
                ]),

            Section::make('Erweitert')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TagsInput::make('web_hosts')
                        ->label('Web-Hosts für A/AAAA und HTTPS-Zertifikat')
                        ->placeholder('Standard: Domain und www.')
                        ->nestedRecursiveRules(['max:253', 'regex:/^[A-Za-z0-9.-]+$/']),
                    TagsInput::make('extra_ips')
                        ->label('Ausgehende Mailserver-IPs')
                        ->placeholder('z. B. 203.0.113.10')
                        ->helperText('Werden zusätzlich zu den MX-IPs auf PTR und Blacklists geprüft.')
                        ->nestedRecursiveRules(['ip']),
                    Toggle::make('check_mx_tls')
                        ->label('STARTTLS-Zertifikate der MX-Hosts prüfen')
                        ->helperText('Benötigt ausgehenden Port 25 auf dem Server.'),
                    Toggle::make('notify_ip_changes')
                        ->label('Bei IP-Änderungen (A/AAAA, PTR) benachrichtigen')
                        ->helperText('Aus: IP-Wechsel (CDN, M365) werden nur protokolliert.'),
                    CheckboxList::make('disabled_checks')
                        ->label('Deaktivierte Prüfungen')
                        ->options(collect(CheckType::options())->except(CheckType::TlsMx->value)->all())
                        ->columns(3)
                        ->columnSpanFull(),
                    TagsInput::make('notification_emails')
                        ->label('Zusätzliche Empfänger')
                        ->placeholder('name@beispiel.de')
                        ->nestedRecursiveRules(['email:rfc', 'max:255'])
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label('Notizen')
                        ->maxLength(5000)
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Übersicht')
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    TextEntry::make('last_status')->label('Status')->badge()->placeholder('noch nicht geprüft'),
                    TextEntry::make('customer.name')->label('Kunde')->placeholder('–'),
                    TextEntry::make('last_checked_at')->label('Zuletzt geprüft')->since()->dateTimeTooltip('d.m.Y H:i')->placeholder('–'),
                    TextEntry::make('next_check_at')->label('Nächste Prüfung')
                        ->state(fn (Domain $record) => $record->active ? ($record->next_check_at ?? now()) : null)
                        ->since()->placeholder('pausiert'),
                    TextEntry::make('check_interval_minutes')->label('Intervall')
                        ->formatStateUsing(fn ($state) => self::INTERVALS[$state] ?? "{$state} Min."),
                    TextEntry::make('dkim')->label('DKIM-Anbieter')
                        ->state(fn (Domain $record) => array_map(fn ($p) => $p->getLabel(), $record->effectiveDkimProviders()))
                        ->badge()->placeholder('keine'),
                    TextEntry::make('notes')->label('Notizen')->placeholder('–')->columnSpan(2),
                ]),
            Section::make('Aktueller Stand')
                ->description(fn (Domain $record) => $record->latestRun
                    ? 'Prüfung vom '.$record->latestRun->finished_at?->format('d.m.Y H:i').' ('.$record->latestRun->duration_ms.' ms)'
                    : null)
                ->schema([ResultSchema::results('latestRun.results')]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'latestRun.results']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('customer.name')
                    ->label('Kunde')
                    ->searchable()
                    ->sortable()
                    ->placeholder('–'),
                TextColumn::make('last_status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->placeholder('neu'),
                TextColumn::make('problems')
                    ->label('Probleme')
                    ->state(fn (Domain $record) => $record->latestRun?->results
                        ->filter(fn ($r) => $r->status->isProblem())
                        ->map(fn ($r) => $r->check->getLabel())
                        ->values()
                        ->all() ?? [])
                    ->badge()
                    ->color('warning')
                    ->limitList(4)
                    ->placeholder('–'),
                TextColumn::make('last_checked_at')
                    ->label('Geprüft')
                    ->since()
                    ->dateTimeTooltip('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('–'),
                ToggleColumn::make('active')
                    ->label('Aktiv'),
            ])
            ->filters([
                SelectFilter::make('last_status')->label('Status')->options(CheckStatus::class),
                SelectFilter::make('customer')->label('Kunde')->relationship('customer', 'name')->searchable()->preload(),
                TernaryFilter::make('active')->label('Aktiv'),
            ])
            ->recordActions([
                self::checkNowAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('checkNow')
                        ->label('Jetzt prüfen')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (Collection $records) {
                            $records->each(fn (Domain $d) => CheckDomainJob::dispatch($d, 'manual'));
                            Notification::make()->title($records->count().' Prüfung(en) gestartet')->success()->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function checkNowAction(): Action
    {
        return Action::make('checkNow')
            ->label('Jetzt prüfen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (Domain $record) {
                CheckDomainJob::dispatch($record, 'manual');
                Notification::make()
                    ->title("Prüfung für {$record->name} gestartet")
                    ->body('Das Ergebnis erscheint in der Regel innerhalb einer Minute.')
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            CheckRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'view' => ViewDomain::route('/{record}'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Domain::query()->active()->whereIn('last_status', [CheckStatus::Warning, CheckStatus::Error])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Domain::query()->active()->where('last_status', CheckStatus::Error)->exists() ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Domains mit Warnungen oder Fehlern';
    }
}
