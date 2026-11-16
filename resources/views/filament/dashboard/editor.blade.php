{{-- Bearbeitungsmodus des Dashboards: Hinweis, ausgeblendete Karten, Darstellung der Ablageflächen. --}}
<div class="saft-dashboard-editor" data-dashboard-editor>
    <style>
        .saft-dashboard-dropzone { min-height: 6rem; border: 2px dashed var(--gray-300); border-radius: .75rem; padding: .5rem; }
        .dark .saft-dashboard-dropzone { border-color: var(--gray-600); }
        .saft-dashboard-dropzone > .fi-grid-col { cursor: default; }
        .saft-dashboard-card-toolbar { display: flex; align-items: center; gap: .5rem; padding: .35rem .6rem; margin-bottom: .35rem; border-radius: .5rem; background: var(--gray-100); font-size: .85rem; }
        .dark .saft-dashboard-card-toolbar { background: var(--gray-800); }
        .saft-dashboard-card-toolbar [data-dashboard-handle] { cursor: grab; color: var(--gray-500); display: inline-flex; }
        .saft-dashboard-card-toolbar .grow { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .saft-dashboard-editor .hidden-cards { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
        .sortable-ghost { opacity: .4; }
    </style>
    <x-filament::section compact>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('dashboard.layout.help') }}</p>
        @if ($hidden !== [])
            <div class="hidden-cards" data-dashboard-hidden>
                <span class="text-sm font-medium">{{ __('dashboard.layout.hidden') }}</span>
                @foreach ($hidden as $card)
                    <x-filament::button size="xs" color="gray" icon="heroicon-m-eye" wire:click="showCard('{{ $card['key'] }}')" data-show-card="{{ $card['key'] }}">
                        {{ $card['label'] }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</div>
