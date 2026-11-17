<x-filament-panels::page>
    <form wire:submit="check">
        {{ $this->form }}

        <div style="margin-top: 1.5rem">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                <span wire:loading.remove wire:target="check">Prüfen</span>
                <span wire:loading wire:target="check">Prüfe …</span>
            </x-filament::button>
        </div>
    </form>

    @if ($checkedName)
        {{ $this->resultInfolist }}
    @endif
</x-filament-panels::page>
