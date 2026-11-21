{{--
    Präsenz erfassen: je Schüler eine Zeile mit drei Knöpfen (anwesend, entschuldigt, unentschuldigt) und Bemerkung.
    Eigene Gestaltung als <style> mit Präfix der Plugin-id (Tailwind-Klassen aus Plugin-Views sind nicht im CSS).
--}}
<x-filament-panels::page>
    {{ $this->content }}

    <style>
        .musikschule-planung-att { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .musikschule-planung-att th { text-align: left; font-weight: 600; padding: .5rem; border-bottom: 1px solid var(--gray-200); }
        .musikschule-planung-att td { padding: .4rem .5rem; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
        .dark .musikschule-planung-att th { border-color: var(--gray-700); }
        .dark .musikschule-planung-att td { border-color: var(--gray-800); }
        .musikschule-planung-seg { display: inline-flex; border: 1px solid var(--gray-300); border-radius: .5rem; overflow: hidden; }
        .dark .musikschule-planung-seg { border-color: var(--gray-600); }
        .musikschule-planung-seg label { padding: .3rem .65rem; cursor: pointer; white-space: nowrap; border-left: 1px solid var(--gray-300); }
        .dark .musikschule-planung-seg label { border-color: var(--gray-600); }
        .musikschule-planung-seg label:first-child { border-left: 0; }
        .musikschule-planung-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .musikschule-planung-seg label:has(input:checked) { color: #fff; }
        .musikschule-planung-seg label.anwesend:has(input:checked) { background: var(--success-600); }
        .musikschule-planung-seg label.entschuldigt:has(input:checked) { background: var(--warning-600); }
        .musikschule-planung-seg label.unentschuldigt:has(input:checked) { background: var(--danger-600); }
        .musikschule-planung-seg label:has(input:focus-visible) { outline: 2px solid var(--primary-500); outline-offset: -2px; }
        .musikschule-planung-note { width: 100%; min-width: 10rem; padding: .3rem .5rem; border: 1px solid var(--gray-300); border-radius: .5rem; background: transparent; color: inherit; }
        .dark .musikschule-planung-note { border-color: var(--gray-600); }
        .musikschule-planung-muted { color: var(--gray-500); font-size: .8rem; }
        .musikschule-planung-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .musikschule-planung-scroll { overflow-x: auto; }
    </style>

    @if ($this->lesson() !== null)
        @if ($entries === [])
            <x-filament::section compact>
                <p class="musikschule-planung-muted">{{ __('musikschule-planung::planung.attendance.empty') }}</p>
            </x-filament::section>
        @else
            <x-filament::section compact>
                <div class="musikschule-planung-bar">
                    <span class="musikschule-planung-muted">
                        @foreach ($this->counts() as $status => $count)
                            {{ __('musikschule-planung::planung.attendance.status.'.$status) }}: {{ $count }}@if (! $loop->last) · @endif
                        @endforeach
                    </span>
                    <x-filament::button size="sm" color="gray" wire:click="setAll('anwesend')">
                        {{ __('musikschule-planung::planung.attendance.all_present') }}
                    </x-filament::button>
                </div>

                <div class="musikschule-planung-scroll">
                    <table class="musikschule-planung-att">
                        <thead>
                            <tr>
                                <th>{{ __('musikschule-planung::planung.attendance.student') }}</th>
                                <th>{{ __('musikschule-planung::planung.attendance.status_label') }}</th>
                                <th>{{ __('musikschule-planung::planung.attendance.remark') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $student => $entry)
                                <tr wire:key="musikschule-planung-att-{{ $student }}">
                                    <td>
                                        {{ $entry['name'] }}
                                        @if (! $entry['active'])
                                            <div class="musikschule-planung-muted">{{ __('musikschule-planung::planung.attendance.not_active') }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="musikschule-planung-seg" role="radiogroup">
                                            @foreach (\Saft\MusikschulePlanung\Pages\Praesenz::STATUSES as $status)
                                                <label class="{{ $status }}">
                                                    <input type="radio" value="{{ $status }}" wire:model.live="entries.{{ $student }}.status">
                                                    {{ __('musikschule-planung::planung.attendance.status.'.$status) }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" class="musikschule-planung-note" maxlength="255" wire:model="entries.{{ $student }}.bemerkung"
                                            aria-label="{{ __('musikschule-planung::planung.attendance.remark') }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="musikschule-planung-bar" style="margin-top: 1rem">
                    <span></span>
                    <x-filament::button wire:click="save" icon="heroicon-o-check" :disabled="! $this->canSave()">
                        {{ __('musikschule-planung::planung.attendance.save') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
