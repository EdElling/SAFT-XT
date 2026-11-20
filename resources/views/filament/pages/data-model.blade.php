{{-- Datenmodell einer Menügruppe als Mermaid-ER-Diagramm; „Felder anzeigen“ schaltet ohne Neuladen um.
     Hell-/Dunkel-Modus: Filament setzt die Klasse „dark“ am html-Element, der Observer zeichnet neu. --}}
<x-filament-panels::page>
    @if ($empty)
        <x-filament::section>
            <p data-data-model-empty>{{ __('data_model.empty') }}</p>
        </x-filament::section>
    @else
        @include('filament.partials.data-model-diagram')
    @endif
</x-filament-panels::page>
