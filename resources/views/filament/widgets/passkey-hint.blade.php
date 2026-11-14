<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-finger-print" icon-color="primary">
        <x-slot name="heading">Passkey einrichten</x-slot>
        <x-slot name="description">
            Melde dich künftig per Fingerabdruck, Gesichtserkennung oder Geräte-PIN an – schneller und sicherer als der E-Mail-Link.
        </x-slot>
        <x-slot name="afterHeader">
            <x-filament::button tag="a" :href="\App\Filament\Pages\Passkeys::getUrl()" icon="heroicon-o-plus">
                Jetzt einrichten
            </x-filament::button>
        </x-slot>
    </x-filament::section>
</x-filament-widgets::widget>
