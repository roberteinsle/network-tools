<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CheckStatus: string implements HasColor, HasIcon, HasLabel
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    /** Prüfung technisch nicht möglich (Timeout o. Ä.) – löst keine Benachrichtigung aus. */
    case Unknown = 'unknown';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Info => 1,
            self::Unknown => 2,
            self::Warning => 3,
            self::Error => 4,
        };
    }

    public function isProblem(): bool
    {
        return $this === self::Warning || $this === self::Error;
    }

    /** @param  iterable<self>  $statuses */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Ok;
        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Info => 'Hinweis',
            self::Warning => 'Warnung',
            self::Error => 'Fehler',
            self::Unknown => 'Nicht prüfbar',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Info => 'info',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::Unknown => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Ok => 'heroicon-o-check-circle',
            self::Info => 'heroicon-o-information-circle',
            self::Warning => 'heroicon-o-exclamation-triangle',
            self::Error => 'heroicon-o-x-circle',
            self::Unknown => 'heroicon-o-question-mark-circle',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Ok => '✅',
            self::Info => 'ℹ️',
            self::Warning => '⚠️',
            self::Error => '❌',
            self::Unknown => '❔',
        };
    }
}
