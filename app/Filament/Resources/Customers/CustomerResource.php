<?php

namespace App\Filament\Resources\Customers;

use App\Enums\DkimProvider;
use App\Enums\NotifyLevel;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\DomainsRelationManager;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Überwachung';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Kunde';

    protected static ?string $pluralModelLabel = 'Kunden';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kunde')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    TextInput::make('contact_email')->label('Kontakt-E-Mail')->email()->maxLength(255),
                    TagsInput::make('notification_emails')
                        ->label('Benachrichtigungen an')
                        ->helperText('Diese Adressen erhalten Meldungen zu allen Domains des Kunden (zusätzlich zu den globalen Empfängern). Ab welchem Status, legt das Feld darunter fest.')
                        ->placeholder('name@beispiel.de')
                        ->nestedRecursiveRules(['email:rfc', 'max:255'])
                        ->columnSpanFull(),
                    Select::make('notify_level')
                        ->label('E-Mail an diese Adressen bei')
                        ->options(NotifyLevel::class)
                        ->default(NotifyLevel::All)
                        ->required()
                        ->helperText(fn ($state) => ($state instanceof NotifyLevel ? $state : NotifyLevel::tryFrom((string) $state))?->getDescription())
                        ->live()
                        ->columnSpanFull(),
                ]),
            Section::make('DKIM-Anbieter')
                ->description('Für diese Anbieter werden die bekannten Selektoren bei allen Domains des Kunden geprüft.')
                ->schema([
                    CheckboxList::make('dkim_providers')
                        ->hiddenLabel()
                        ->options(DkimProvider::class)
                        ->descriptions(collect(DkimProvider::cases())->mapWithKeys(fn ($p) => [$p->value => $p->getDescription()])->all())
                        ->columns(2),
                ]),
            Section::make('Notizen')
                ->collapsed()
                ->schema([
                    Textarea::make('notes')->hiddenLabel()->maxLength(5000)->rows(4),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('domains_count')->label('Domains')->counts('domains')->sortable(),
                TextColumn::make('notify_level')->label('E-Mail bei')->badge()->color('gray')
                    ->description(fn (Customer $record) => $record->notification_emails ? count($record->notification_emails).' Empfänger' : 'keine Empfänger'),
                TextColumn::make('dkim_providers')
                    ->label('DKIM-Anbieter')
                    ->formatStateUsing(fn ($state) => DkimProvider::tryFrom($state)?->getLabel() ?? $state)
                    ->badge()
                    ->color('gray')
                    ->placeholder('–'),
                TextColumn::make('contact_email')->label('Kontakt')->placeholder('–')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DomainsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
