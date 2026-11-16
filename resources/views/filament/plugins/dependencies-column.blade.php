{{-- Spalte „Abhängigkeiten“ auf der Seite Plugins: Zustand als Badges, daneben der Knopf für die Aufstellung. --}}
@php
    $record = $getRecord();
@endphp
<div class="fi-ta-text" style="display: flex; align-items: flex-start; gap: .5rem; padding: .75rem 1rem;">
    <div style="display: flex; flex-direction: column; gap: .25rem;">
        @foreach ($record['dependencies'] as $line)
            <x-filament::badge :color="$record['satisfied'] ? 'success' : 'danger'" style="white-space: normal;">{{ $line }}</x-filament::badge>
        @endforeach
    </div>
    @if ($record['required'])
        <x-filament::icon-button
            icon="heroicon-o-table-cells"
            color="gray"
            size="sm"
            :label="__('plugins.dependencies.show')"
            :tooltip="__('plugins.dependencies.show')"
            wire:click="mountAction('dependencies', { plugin: '{{ $record['id'] }}' })"
            data-plugin-dependencies-button="{{ $record['id'] }}"
        />
    @endif
</div>
