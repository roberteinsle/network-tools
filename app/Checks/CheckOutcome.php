<?php

namespace App\Checks;

use App\Enums\CheckStatus;

/**
 * Ergebnis einer Prüfung.
 *  - messages: Einzelbefunde mit eigenem Status (werden angezeigt und bestimmen den Gesamtstatus)
 *  - data:     Schlüssel/Wert-Paare zur Anzeige (z. B. gefundene Records)
 *  - state:    vergleichbarer Zustand für die Änderungserkennung (ohne flüchtige Werte wie Resttage)
 */
final class CheckOutcome
{
    /** @var list<array{status:string, text:string}> */
    private array $messages = [];

    /** @var array<string, string> */
    private array $data = [];

    private array $state = [];

    private ?string $summary = null;

    private ?CheckStatus $forcedStatus = null;

    private bool $infoIsNeutral = false;

    public static function make(): self
    {
        return new self;
    }

    public function ok(string $text): self
    {
        return $this->add(CheckStatus::Ok, $text);
    }

    public function info(string $text): self
    {
        return $this->add(CheckStatus::Info, $text);
    }

    /** Prüfung für diese Domain ohne Bedeutung (zählt nie als Problem, kein Alarm). */
    public function notApplicable(string $text): self
    {
        return $this->add(CheckStatus::NotApplicable, $text);
    }

    public function warning(string $text): self
    {
        return $this->add(CheckStatus::Warning, $text);
    }

    public function error(string $text): self
    {
        return $this->add(CheckStatus::Error, $text);
    }

    public function add(CheckStatus $status, string $text): self
    {
        $this->messages[] = ['status' => $status->value, 'text' => $text];

        return $this;
    }

    public function data(string $key, string|array|int|float|bool|null $value): self
    {
        if (is_array($value)) {
            $value = $value === [] ? '–' : implode("\n", $value);
        } elseif (is_bool($value)) {
            $value = $value ? 'ja' : 'nein';
        }
        $this->data[$key] = (string) ($value ?? '–');

        return $this;
    }

    public function state(array $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function forceStatus(CheckStatus $status): self
    {
        $this->forcedStatus = $status;

        return $this;
    }

    /**
     * Hinweise bestimmen den Gesamtstatus nicht, sobald es mindestens einen OK-Befund gibt:
     * maßgeblich sind dann nur Warnungen, Fehler und „nicht prüfbar“.
     */
    public function infoIsNeutral(bool $neutral = true): self
    {
        $this->infoIsNeutral = $neutral;

        return $this;
    }

    public function status(): CheckStatus
    {
        if ($this->forcedStatus) {
            return $this->forcedStatus;
        }

        $statuses = array_map(fn ($m) => CheckStatus::from($m['status']), $this->messages);
        $worst = CheckStatus::worst($statuses);

        if ($this->infoIsNeutral && $worst === CheckStatus::Info && in_array(CheckStatus::Ok, $statuses, true)) {
            return CheckStatus::Ok;
        }

        return $worst;
    }

    public function getSummary(): string
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        // Standard: wichtigste Meldung (höchster Schweregrad, erste ihrer Art)
        $status = $this->status();
        foreach ($this->messages as $m) {
            if ($m['status'] === $status->value) {
                return $m['text'];
            }
        }

        return $status->getLabel();
    }

    public function details(): array
    {
        return ['messages' => $this->messages, 'data' => $this->data];
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->status()->value.'|'.json_encode(self::sortRecursive($this->state)));
    }

    private static function sortRecursive(array $value): array
    {
        foreach ($value as &$v) {
            if (is_array($v)) {
                $v = self::sortRecursive($v);
            }
        }
        array_is_list($value) ? sort($value) : ksort($value);

        return $value;
    }
}
