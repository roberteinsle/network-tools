<?php

namespace App\Enums;

use App\Models\CheckResult;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Collection;

/**
 * Ab welchem Status die Empfänger eines Kunden eine Alarm-Mail bekommen.
 * „Nicht prüfbar“ löst unabhängig davon nie eine Mail aus (siehe ChangeDetector).
 */
enum NotifyLevel: string implements HasDescription, HasLabel
{
    case All = 'all';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Alle Änderungen',
            self::Info => 'Ab Hinweis',
            self::Warning => 'Ab Warnung',
            self::Error => 'Nur Fehler',
            self::None => 'Keine E-Mails',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::All => 'Jede gemeldete Änderung, auch geänderte Records ohne Problem.',
            self::Info => 'Änderungen, die zu einem Hinweis, einer Warnung oder einem Fehler führen oder davon zurückkehren.',
            self::Warning => 'Änderungen, die zu einer Warnung oder einem Fehler führen oder davon zurückkehren.',
            self::Error => 'Nur Änderungen, die zu einem Fehler führen oder einen Fehler beheben.',
            self::None => 'Die Adressen des Kunden bekommen keine E-Mails.',
        };
    }

    private function threshold(): ?int
    {
        return match ($this) {
            self::Info => CheckStatus::Info->severity(),
            self::Warning => CheckStatus::Warning->severity(),
            self::Error => CheckStatus::Error->severity(),
            default => null,
        };
    }

    /**
     * Entscheidet, ob eine Alarm-Mail für diese Stufe relevant ist.
     *
     * @param  list<array{kind:string, from:?string, to:string}>  $changes
     * @param  Collection<int, CheckResult>  $results  Ergebnisse des aktuellen Laufs
     */
    public function wants(array $changes, Collection $results, bool $initial): bool
    {
        if ($this === self::None) {
            return false;
        }
        if ($this === self::All) {
            return true;
        }

        $min = $this->threshold();
        $reaches = fn (?string $status) => $status !== null
            && ($s = CheckStatus::tryFrom($status)) !== null
            && $s !== CheckStatus::Unknown
            && $s->severity() >= $min;

        if ($initial) {
            return $results->contains(fn (CheckResult $r) => $reaches($r->status->value));
        }

        foreach ($changes as $change) {
            // neu auf/über der Stufe – oder von dort zurück (Entwarnung)
            if ($reaches($change['to']) || $reaches($change['from'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
