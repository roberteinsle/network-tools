<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Neuen Passkey hinzufügen</x-slot>
        <x-slot name="description">Passkeys sind die bevorzugte Anmeldung. Der Magic Link per E-Mail bleibt als Ausweg erhalten.</x-slot>

        <div
            x-data="{ name: '', busy: false, error: null }"
            style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-start"
        >
            <div style="flex: 1 1 16rem; max-width: 24rem">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        x-model="name"
                        maxlength="100"
                        placeholder="Name, z. B. MacBook oder iPhone"
                    />
                </x-filament::input.wrapper>
            </div>

            <x-filament::button
                icon="heroicon-o-finger-print"
                x-bind:disabled="busy"
                x-on:click="
                    busy = true; error = null;
                    error = await window.DomainMonitorPasskeys.register(name.trim());
                    busy = false;
                    if (error === null) { name = ''; $wire.$refresh(); new FilamentNotification().title('Passkey gespeichert').success().send(); }
                "
            >
                Passkey hinzufügen
            </x-filament::button>

            <p x-show="error" x-text="error" style="flex-basis: 100%; color: var(--danger-600); font-size: 0.875rem"></p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
