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
    /** Prüfung für diese Domain ohne Bedeutung (z. B. MTA-STS ohne Mailempfang) – zählt nie als Problem. */
    case NotApplicable = 'na';

    public function severity(): int
    {
        return match ($this) {
            self::NotApplicable => -1,
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

    /** Ohne Handlungsbedarf und ohne Hinweis: Details eingeklappt, keine KI-Empfehlung. */
    public function isQuiet(): bool
    {
        return $this === self::Ok || $this === self::NotApplicable;
    }

    /** @param  iterable<self>  $statuses */
    public static function worst(iterable $statuses): self
    {
        $worst = null;
        foreach ($statuses as $status) {
            if ($worst === null || $status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst ?? self::Ok;
    }

    /**
     * Gesamtstatus einer Domain: schlechtester Check-Status, wobei Hinweise und
     * „nicht relevant“ wie OK zählen – sie beeinflussen den Gesamtstatus nicht.
     *
     * @param  iterable<self>  $statuses
     */
    public static function overall(iterable $statuses): self
    {
        $mapped = [];
        foreach ($statuses as $status) {
            $mapped[] = in_array($status, [self::Info, self::NotApplicable], true) ? self::Ok : $status;
        }

        return self::worst($mapped);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Info => 'Hinweis',
            self::Warning => 'Warnung',
            self::Error => 'Fehler',
            self::Unknown => 'Nicht prüfbar',
            self::NotApplicable => 'Nicht relevant',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Info => 'info',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::Unknown, self::NotApplicable => 'gray',
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
            self::NotApplicable => 'heroicon-o-minus-circle',
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
            self::NotApplicable => '➖',
        };
    }
}
