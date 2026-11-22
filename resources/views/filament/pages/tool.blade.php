<x-filament-panels::page>
    <form wire:submit="lookup">
        {{ $this->form }}

        <div style="margin-top: 1rem">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                <span wire:loading.remove wire:target="lookup">Abfragen</span>
                <span wire:loading wire:target="lookup">Frage ab …</span>
            </x-filament::button>
        </div>
    </form>

    @if ($queried)
        {{ $this->resultInfolist }}
    @endif
</x-filament-panels::page>
