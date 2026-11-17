@php($state = $getState())
@if ($state)
    @livewire(\App\Livewire\Copilot::class, $state, key('copilot-'.md5(json_encode($state))))
@endif
