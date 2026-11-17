@if ($getState())
    {{-- @js nur in normalem HTML: in Attributen von <x-…>-Komponenten wertet Blade Direktiven nicht aus --}}
    <div x-data="{ check: @js($getState()) }">
        <x-filament::button size="xs" color="primary" outlined icon="heroicon-o-sparkles"
            x-on:click="$dispatch('copilot-open', { check })">
            KI-Empfehlung
        </x-filament::button>
    </div>
@endif
