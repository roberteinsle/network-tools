<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;
use UnitEnum;

/** Eigene Passkeys verwalten (Registrierung im Browser über @laravel/passkeys). */
class Passkeys extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Einstellungen';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Meine Passkeys';

    protected static ?string $navigationLabel = 'Passkeys';

    protected string $view = 'filament.pages.passkeys';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Passkey::query()->where('user_id', auth()->id()))
            ->heading('Registrierte Passkeys')
            ->emptyStateHeading('Noch kein Passkey eingerichtet')
            ->emptyStateDescription('Mit einem Passkey meldest du dich per Fingerabdruck, Gesichtserkennung oder Geräte-PIN an – ohne E-Mail-Link.')
            ->columns([
                TextColumn::make('name')->label('Name')->weight('medium'),
                TextColumn::make('created_at')->label('Angelegt')->dateTime('d.m.Y H:i'),
                TextColumn::make('last_used_at')->label('Zuletzt verwendet')->since()->placeholder('noch nie'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('Danach ist eine Anmeldung mit diesem Passkey nicht mehr möglich.')
                    ->using(fn (Passkey $record) => app(DeletePasskey::class)(auth()->user(), $record)),
            ]);
    }
}
