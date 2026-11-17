<?php

namespace App\Livewire;

use App\Enums\CheckType;
use App\Filament\Resources\AiModels\AiModelResource;
use App\Models\AiModel;
use App\Services\Ai\AiException;
use App\Services\Copilot\Copilot as CopilotService;
use App\Services\Copilot\CopilotPrompt;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * KI-Copilot und Export für die Ergebnisse einer Domain. Wird über Browser-Events geöffnet:
 * copilot-open {check?} (null = alle Probleme) und copilot-export.
 */
class Copilot extends Component
{
    #[Locked]
    public string $domain;

    /** @var list<array> */
    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $checkedAt = null;

    public ?string $check = null;

    public ?int $modelId = null;

    public ?string $answer = null;

    public ?string $error = null;

    public function mount(string $domain, array $results, ?string $checkedAt = null): void
    {
        $this->domain = $domain;
        $this->results = CopilotPrompt::payload($results);
        $this->checkedAt = $checkedAt;
        $this->modelId = AiModel::preferred()?->id;
    }

    public function modalId(string $suffix): string
    {
        return 'copilot-'.$suffix.'-'.$this->getId();
    }

    #[On('copilot-open')]
    public function open(?string $check = null): void
    {
        $this->check = $check;
        $this->answer = null;
        $this->error = null;
        $this->dispatch('open-modal', id: $this->modalId('answer'));

        if ($this->modelId !== null) {
            // Zweite Anfrage, damit das Modal sofort mit Ladeanzeige erscheint
            $this->js('$wire.ask()');
        }
    }

    #[On('copilot-export')]
    public function openExport(): void
    {
        $this->dispatch('open-modal', id: $this->modalId('export'));
    }

    public function ask(bool $fresh = false, ?CopilotService $copilot = null): void
    {
        $this->answer = null;
        $this->error = null;
        $model = $this->modelId ? AiModel::query()->active()->find($this->modelId) : null;
        if ($model === null) {
            $this->error = 'Kein aktives KI-Modell ausgewählt.';

            return;
        }

        @set_time_limit(180);
        try {
            $this->answer = ($copilot ?? app(CopilotService::class))->recommend($model, $this->domain, $this->results, $this->check, $fresh);
        } catch (AiException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function updatedModelId(): void
    {
        $this->ask();
    }

    public function getTitleProperty(): string
    {
        return $this->check !== null
            ? 'KI-Empfehlung: '.(CheckType::tryFrom($this->check)?->getLabel() ?? $this->check)
            : 'KI-Empfehlungen für '.$this->domain;
    }

    public function render()
    {
        return view('livewire.copilot', [
            'models' => AiModel::query()->active()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'model']),
            'answerHtml' => $this->answer !== null
                ? Str::markdown($this->answer, ['html_input' => 'escape', 'allow_unsafe_links' => false])
                : null,
            'exportText' => CopilotPrompt::export($this->domain, $this->results, $this->checkedAt),
            'settingsUrl' => AiModelResource::getUrl(),
        ]);
    }
}
