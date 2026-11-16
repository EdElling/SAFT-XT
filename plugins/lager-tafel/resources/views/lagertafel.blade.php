{{--
    Lagertafel: je Lagerort eine Karte. Alle Karten bilden eine wire:sort-Gruppe; der Bestand lässt sich von einer Karte
    auf eine andere ziehen. Beim Ablegen ruft Livewire $wire.drop(Quellort, Position, Zielort) auf.
    SAFT liefert die Oberfläche mit dem fertig übersetzten CSS von Filament aus – Tailwind-Klassen aus Plugin-Views
    sind darin nicht enthalten. Eigene Gestaltung daher als <style> mit Präfix der Plugin-id.
--}}
<x-filament-panels::page>
    {{ $this->content }}

    <style>
        .lager-tafel-board { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); }
        .lager-tafel-drop { min-height: 4.5rem; display: flex; align-items: center; justify-content: center; padding: .75rem;
            border: 2px dashed var(--gray-300); border-radius: .75rem; }
        .dark .lager-tafel-drop { border-color: var(--gray-600); }
        .lager-tafel-drop:has(.sortable-ghost) { border-color: var(--primary-500); background: color-mix(in oklab, var(--primary-500) 8%, transparent); }
        .lager-tafel-item { cursor: grab; }
        .lager-tafel-item:active { cursor: grabbing; }
        .lager-tafel-empty { font-size: .875rem; color: var(--gray-500); }
    </style>

    @if ($this->article() !== null)
        <p class="lager-tafel-empty">{{ __('lager-tafel::tafel.board.help') }}</p>

        <div class="lager-tafel-board">
            @foreach ($this->board() as $card)
                <x-filament::section :heading="$card['label']" compact wire:key="lager-tafel-card-{{ $card['id'] }}">
                    <div
                        class="lager-tafel-drop"
                        wire:sort="drop"
                        wire:sort:group="lager-tafel"
                        wire:sort:group-id="{{ $card['id'] }}"
                        data-location="{{ $card['id'] }}"
                    >
                        @if ($card['quantity'] > 0)
                            <div
                                class="lager-tafel-item"
                                wire:key="lager-tafel-item-{{ $card['id'] }}-{{ $card['quantity'] }}"
                                wire:sort:item="{{ $card['id'] }}"
                                title="{{ __('lager-tafel::tafel.board.drag') }}"
                            >
                                <x-filament::badge size="lg" icon="heroicon-m-cube">
                                    {{ $card['quantity'] }}
                                </x-filament::badge>
                            </div>
                        @else
                            <span class="lager-tafel-empty" wire:sort:ignore>{{ __('lager-tafel::tafel.board.empty') }}</span>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
