{{-- Felder in Blöcken: Name | Wert, spaltenweise gefüllt (siehe App\Filament\Support\FieldBlocks). --}}
<div class="saft-field-blocks" data-field-blocks data-blocks="{{ count($blocks) }}" style="--blocks: {{ max(1, count($blocks)) }}">
    <style>
        .saft-field-blocks { container-type: inline-size; width: 100%; }
        /* Die Blöcke richten sich nach der verfügbaren Breite (Container-Abfrage) und haben daher keine eigene
           Inhaltsbreite. Die Datenansicht macht Abschnitte sonst nur so breit wie ihr Inhalt (PanelAppearance) –
           hier also die volle verfügbare Breite. */
        .saft-data-page .fi-page-content .fi-sc-section:has(.saft-field-blocks), .fi-section:has(.saft-field-blocks) { width: 100%; max-width: 100%; }
        .saft-field-blocks > .grid { display: grid; gap: .35rem 2rem; grid-template-columns: repeat(var(--blocks), minmax(0, 1fr)); align-items: start; }
        @container (max-width: 52rem) { .saft-field-blocks > .grid { grid-template-columns: repeat(min(var(--blocks), 2), minmax(0, 1fr)); } }
        @container (max-width: 30rem) { .saft-field-blocks > .grid { grid-template-columns: minmax(0, 1fr); } }
        .saft-field-blocks dl { display: grid; grid-template-columns: minmax(6rem, max-content) minmax(0, 1fr); gap: .35rem .9rem; margin: 0; align-items: start; }
        .saft-field-blocks dt { font-size: .875rem; font-weight: 500; color: var(--gray-500); max-width: 16rem; overflow-wrap: anywhere; }
        .dark .saft-field-blocks dt { color: var(--gray-400); }
        .saft-field-blocks dd { margin: 0; font-size: .875rem; color: var(--gray-950); overflow-wrap: anywhere; white-space: pre-line; }
        .dark .saft-field-blocks dd { color: var(--color-white, #fff); }
        .saft-field-blocks dd.num { font-variant-numeric: tabular-nums; }
        .saft-field-blocks dd.empty { color: var(--gray-400); }
    </style>
    <div class="grid">
        @foreach ($blocks as $block)
            <dl>
                @foreach ($block as $field)
                    <dt>{{ $field['label'] }}</dt>
                    <dd @class(['num' => ($field['numeric'] ?? false) && filled($field['value']), 'empty' => blank($field['value'])])>{{ filled($field['value']) ? $field['value'] : $empty }}</dd>
                @endforeach
            </dl>
        @endforeach
    </div>
</div>
