{{-- Griff und „Ausblenden“ einer Karte im Bearbeitungsmodus. --}}
<div class="saft-dashboard-card-toolbar">
    <span data-dashboard-handle title="{{ __('dashboard.layout.move') }}" aria-label="{{ __('dashboard.layout.move') }}">
        <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-4 w-4" />
    </span>
    <span class="grow">{{ $label }}</span>
    <x-filament::link tag="button" size="sm" color="gray" icon="heroicon-m-eye-slash" wire:click="hideCard('{{ $key }}')" data-hide-card="{{ $key }}">
        {{ __('dashboard.layout.hide') }}
    </x-filament::link>
</div>
