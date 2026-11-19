{{-- Umschalter der aktuellen App (B-107), nur bei mehr als einer zugänglichen App. --}}
<x-filament::dropdown placement="bottom-start" data-app-switcher>
    <x-slot name="trigger">
        <button type="button" class="saft-app-switcher" aria-label="{{ __('apps.switch.label') }}" title="{{ __('apps.switch.label') }}">
            @if ($current?->icon)
                {{ svg($current->icon, 'saft-app-switcher-icon') }}
            @else
                {{ svg('heroicon-o-squares-2x2', 'saft-app-switcher-icon') }}
            @endif
            <span class="saft-app-switcher-name">{{ $current?->name ?? __('apps.switch.none') }}@if ($current && ! $current->is_active) ({{ __('apps.inactive') }})@endif</span>
            {{ svg('heroicon-m-chevron-down', 'saft-app-switcher-chevron') }}
        </button>
    </x-slot>

    <x-filament::dropdown.list>
        @foreach ($apps as $app)
            <x-filament::dropdown.list.item
                tag="form"
                method="post"
                :action="route('apps.switch', ['app' => $app->id])"
                :icon="$app->icon ?: 'heroicon-o-squares-2x2'"
                :color="$app->id === $current?->id ? 'primary' : 'gray'"
                data-app-switch="{{ $app->id }}"
            >
                {{ $app->name }}@unless ($app->is_active) <span class="saft-app-switcher-inactive">({{ __('apps.inactive') }})</span>@endunless
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
