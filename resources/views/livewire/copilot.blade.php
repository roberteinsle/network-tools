{{-- Inline-Styles statt Tailwind-Klassen: das Panel-CSS von Filament enthält keine eigenen Utility-Klassen. --}}
<div>
    <style>
        .copilot-answer { max-width: none; min-width: 0; overflow-wrap: anywhere; }
        .copilot-answer pre { max-width: 100%; overflow-x: auto; overflow-wrap: normal; white-space: pre; }
        /* Grid-/Flex-Elemente des Modals dürfen schmaler werden als ein langer Code-Block */
        .copilot-modal .fi-modal-window, .copilot-modal .fi-modal-content { min-width: 0; }
    </style>
    <x-filament::modal :id="$this->modalId('answer')" width="4xl" :heading="$this->title" class="copilot-modal">
        @if ($models->isEmpty())
            <p style="font-size: .875rem; color: var(--gray-600)">
                Noch kein KI-Modell angelegt.
                <x-filament::link :href="$settingsUrl">Unter Einstellungen → KI-Modelle</x-filament::link>
                ein Modell hinterlegen oder den Stand über „Export für KI-Chat“ in ein Chatfenster kopieren.
            </p>
        @else
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: .75rem">
                <div style="flex: 1 1 18rem; min-width: 0">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="modelId" aria-label="KI-Modell">
                            @foreach ($models as $model)
                                <option value="{{ $model->id }}">{{ $model->name }} ({{ $model->model }})</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="ask(true)" wire:loading.attr="disabled" wire:target="ask,modelId">
                    Neu generieren
                </x-filament::button>
            </div>

            <p style="font-size: .75rem; color: var(--gray-500)">
                Die Prüfergebnisse dieser Domain werden an den Anbieter des Modells übertragen.
            </p>

            <div wire:loading.flex wire:target="ask,modelId" style="align-items: center; gap: .5rem; padding: 1.5rem 0; font-size: .875rem; color: var(--gray-600)">
                <x-filament::loading-indicator style="width: 1.25rem; height: 1.25rem" />
                Empfehlung wird erstellt …
            </div>

            <div wire:loading.remove wire:target="ask,modelId">
                @if ($error)
                    <div role="alert" style="border-radius: .5rem; padding: .75rem 1rem; font-size: .875rem; background: var(--danger-50); color: var(--danger-700); border: 1px solid var(--danger-200)">
                        {{ $error }}
                    </div>
                @elseif ($answerHtml)
                    <div class="fi-prose copilot-answer">
                        {!! $answerHtml !!}
                    </div>
                    <div style="margin-top: 1rem" x-data="{ copied: false, answer: @js($answer) }">
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-clipboard"
                            x-on:click="navigator.clipboard.writeText(answer).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <span x-show="! copied">Antwort kopieren</span>
                            <span x-show="copied" x-cloak>Kopiert</span>
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::modal>

    <x-filament::modal :id="$this->modalId('export')" width="4xl" heading="Export für KI-Chat" class="copilot-modal">
        <p style="font-size: .875rem; color: var(--gray-600)">
            Anweisung und aktueller Stand aller Prüfungen als Text – zum Einfügen in ChatGPT, Claude, Gemini oder ein anderes KI-Chatfenster.
        </p>
        <div x-data="{ copied: false }" style="display: grid; gap: .75rem">
            <textarea x-ref="text" readonly rows="16" aria-label="Export-Text"
                style="display: block; width: 100%; box-sizing: border-box; padding: .75rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: var(--gray-50); color: var(--gray-900); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; line-height: 1.5; resize: vertical">{{ $exportText }}</textarea>
            <div style="display: flex; flex-wrap: wrap; gap: .5rem">
                <x-filament::button icon="heroicon-o-clipboard"
                    x-on:click="navigator.clipboard ? navigator.clipboard.writeText($refs.text.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) }) : ($refs.text.select(), document.execCommand('copy'), copied = true)">
                    <span x-show="! copied">In die Zwischenablage kopieren</span>
                    <span x-show="copied" x-cloak>Kopiert</span>
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" tag="a"
                    :href="'data:text/markdown;charset=utf-8,'.rawurlencode($exportText)"
                    :download="'domain-check-'.$domain.'.md'">
                    Als Markdown speichern
                </x-filament::button>
            </div>
        </div>
    </x-filament::modal>
</div>
