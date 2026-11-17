{{-- Herkunft aus einem Plugin (PluginOrigin::badge): unter dem Seitentitel bzw. unter einer Kachel. --}}
@php
    $tag = $url ? 'a' : 'span';
@endphp
<div @class(['saft-plugin-origin', 'saft-plugin-origin-card' => $variant === 'card']) data-plugin-origin="{{ $plugin->id() }}">
    <{{ $tag }}
        @if ($url) href="{{ $url }}" wire:navigate @endif
        title="{{ $tooltip }}"
        class="saft-plugin-origin-badge"
    >
        <x-filament::icon icon="heroicon-m-puzzle-piece" class="saft-plugin-origin-icon" />
        <span>{{ __('plugins.origin.badge', ['plugin' => $plugin->label()]) }}</span>
    </{{ $tag }}>
</div>
