<?php

namespace App\Services\Lookup;

/**
 * Ergebnistabelle eines Werkzeugs als einfaches Array (Livewire-tauglich).
 * Zellen: Text, Liste von Texten (eine Zeile je Eintrag) oder null.
 * Badge-Spalten enthalten einen CheckStatus-Wert (ok, info, warning, error, unknown).
 */
final class ToolTable
{
    private array $columns = [];

    private array $rows = [];

    private ?string $empty = null;

    public function __construct(private string $heading, private ?string $description = null) {}

    public static function make(string $heading, ?string $description = null): self
    {
        return new self($heading, $description);
    }

    public function column(string $label, bool $mono = false, bool $badge = false): self
    {
        $this->columns[] = ['label' => $label, 'mono' => $mono, 'badge' => $badge];

        return $this;
    }

    /** @param  list<string|list<string>|int|null>  $cells */
    public function row(array $cells): self
    {
        $this->rows[] = array_map(fn ($c) => is_int($c) ? (string) $c : $c, array_values($cells));

        return $this;
    }

    public function empty(string $text): self
    {
        $this->empty = $text;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'heading' => $this->heading,
            'description' => $this->description,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'empty' => $this->empty ?? 'Keine Einträge gefunden.',
        ];
    }
}
