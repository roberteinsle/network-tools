<div
    x-data="{ busy: false, error: null, supported: true }"
    x-init="supported = window.DomainMonitorPasskeys?.supported() ?? false"
    style="display: grid; gap: 0.75rem"
>
    <template x-if="supported">
        <div style="display: grid; gap: 0.75rem">
            <x-filament::button
                size="lg"
                icon="heroicon-o-finger-print"
                x-bind:disabled="busy"
                x-on:click="busy = true; error = await window.DomainMonitorPasskeys.login(); busy = false"
                style="width: 100%"
            >
                Mit Passkey anmelden
            </x-filament::button>

            <p x-show="error" x-text="error" style="color: var(--danger-600); font-size: 0.875rem; text-align: center"></p>

            <div style="display: flex; align-items: center; gap: 0.75rem; color: var(--gray-500); font-size: 0.875rem">
                <hr style="flex: 1; border-color: var(--gray-200)">
                <span>oder per E-Mail-Link</span>
                <hr style="flex: 1; border-color: var(--gray-200)">
            </div>
        </div>
    </template>
</div>
