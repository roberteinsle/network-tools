<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Jobs\CheckDomainJob;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    /** Per Link vorbelegbar: ?name=beispiel.de */
    protected function afterFill(): void
    {
        if (filled($name = request()->query('name'))) {
            $this->form->fill([...$this->form->getRawState(), 'name' => (string) $name]);
        }
    }

    /** Neue Domains sofort einmal prüfen. */
    protected function afterCreate(): void
    {
        if ($this->record->active) {
            $this->record->forceFill(['next_check_at' => now()->addMinutes($this->record->check_interval_minutes)])->saveQuietly();
            CheckDomainJob::dispatch($this->record, 'manual');
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
