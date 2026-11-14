<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Domains\DomainResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    protected static ?string $title = 'Domains';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Domain')->searchable()->weight('medium'),
                TextColumn::make('last_status')->label('Status')->badge()->placeholder('neu'),
                TextColumn::make('last_checked_at')->label('Geprüft')->since()->placeholder('–'),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('Domain hinzufügen')
                    ->url(fn () => DomainResource::getUrl('create')),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => DomainResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
