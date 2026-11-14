<?php

namespace App\Filament\Resources\Domains\RelationManagers;

use App\Filament\Resources\CheckRuns\CheckRunResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class CheckRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'checkRuns';

    protected static ?string $title = 'Protokoll';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(CheckRunResource::columns(withDomain: false))
            ->filters([
                Filter::make('changes')->label('Nur mit Änderungen')->query(fn ($q) => $q->where('changes_count', '>', 0)),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => CheckRunResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
