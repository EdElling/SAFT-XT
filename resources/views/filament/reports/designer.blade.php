{{-- Editor einer Auswertung: Felder (links) – Vorschau (Mitte) – Aufbau (rechts).
     Ziehen und Ablegen mit SortableJS (von Filament geladen, forceFallback: auch per Maus-Ereignissen und
     in Browser-Tests); jede Aktion gibt es auch als Knopf bzw. Menü („＋“) für Tastatur und Screenreader. --}}
@php
    $zones = [
        'rows' => $grouped ? __('reports.ui.zones.rows') : __('reports.ui.zones.list'),
        'columns' => __('reports.ui.zones.columns'),
        'values' => __('reports.ui.zones.values'),
        'filters' => __('reports.ui.zones.filters'),
    ];
    $kindIcon = ['number' => '#', 'date' => '📅', 'boolean' => '☑', 'relation' => '→', 'text' => 'Aa'];
    $state = $this->state;
@endphp
<x-filament-panels::page>
    <style>
        .saft-designer { display: grid; grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr) minmax(16rem, 20rem); gap: 1rem; align-items: start; }
        @media (max-width: 1100px) { .saft-designer { grid-template-columns: 1fr; } }
        .saft-designer .pane { border: 1px solid var(--gray-200); border-radius: .75rem; background: var(--color-white, #fff); padding: .75rem; min-width: 0; }
        .dark .saft-designer .pane { border-color: var(--gray-700); background: var(--gray-900); }
        .saft-designer h3 { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--gray-500); margin: .5rem 0 .35rem; }
        .saft-designer .catalog { max-height: 70vh; overflow: auto; }
        .saft-designer .catalog ul, .saft-designer .zone { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .3rem; }
        .saft-designer .zone { min-height: 2.5rem; border: 1px dashed var(--gray-300); border-radius: .5rem; padding: .35rem; }
        .dark .saft-designer .zone { border-color: var(--gray-600); }
        .saft-designer .zone:empty::before { content: attr(data-empty); color: var(--gray-400); font-size: .8rem; padding: .25rem; }
        .saft-designer .chip { display: flex; align-items: center; gap: .35rem; padding: .3rem .45rem; border-radius: .4rem; background: var(--gray-50); border: 1px solid var(--gray-200); font-size: .85rem; }
        .dark .saft-designer .chip { background: var(--gray-800); border-color: var(--gray-700); }
        .saft-designer .chip .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .saft-designer .zone .chip { flex-wrap: wrap; }
        /* Auswahl (Berechnung, Stufe) in eigener Zeile unter dem Feldnamen, damit der Name lesbar bleibt. */
        .saft-designer .chip select { order: 10; flex-basis: calc(100% - 1.6rem); margin-inline-start: 1.6rem; font-size: .78rem; padding: .05rem 1.4rem .05rem .3rem; border-radius: .3rem; border: 1px solid var(--gray-300); background-color: transparent; }
        .saft-designer .handle { cursor: grab; color: var(--gray-400); user-select: none; }
        .saft-designer .kind { display: inline-block; min-width: 1.4rem; text-align: center; color: var(--gray-500); font-size: .75rem; }
        .saft-designer .icon-btn { border: 0; background: none; color: var(--gray-500); cursor: pointer; padding: 0 .15rem; font-size: .9rem; }
        .saft-designer .icon-btn:hover, .saft-designer .icon-btn:focus-visible { color: var(--primary-600); }
        .saft-designer .chip.problem { border-color: var(--danger-400, #f87171); }
        .saft-designer .visuals { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .75rem; }
        .saft-designer .sentence { font-style: italic; color: var(--gray-600); margin: .25rem 0 .75rem; }
        .dark .saft-designer .sentence { color: var(--gray-400); }
        .saft-designer .hint { color: var(--gray-500); font-size: .875rem; padding: 2rem 0; text-align: center; }
        .saft-designer .fi-sortable-ghost, .saft-designer .sortable-ghost { opacity: .4; }
        .saft-designer input.search { width: 100%; border: 1px solid var(--gray-300); border-radius: .4rem; padding: .35rem .5rem; font-size: .875rem; background: transparent; }
        .saft-designer .base-picker { position: relative; }
        .saft-designer .base-button { width: 100%; display: flex; align-items: center; gap: .4rem; border: 1px solid var(--gray-300); border-radius: .4rem; padding: .35rem .5rem; font-size: .875rem; background: transparent; text-align: start; cursor: pointer; }
        .saft-designer .base-button .grow, .saft-designer .base-option .grow { flex: 1; min-width: 0; }
        .saft-designer .base-button .placeholder { color: var(--gray-500); }
        .saft-designer .base-chevron { width: 1rem; height: 1rem; color: var(--gray-400); }
        .saft-designer .base-panel { position: absolute; z-index: 30; inset-inline: 0; top: calc(100% + .25rem); border: 1px solid var(--gray-200); border-radius: .5rem; background: var(--color-white, #fff); box-shadow: 0 10px 25px -5px rgb(0 0 0 / .15); padding: .4rem; }
        .dark .saft-designer .base-panel { border-color: var(--gray-700); background: var(--gray-900); }
        .saft-designer .base-panel ul { list-style: none; margin: .35rem 0 0; padding: 0; max-height: 18rem; overflow: auto; }
        .saft-designer .base-option { width: 100%; display: flex; align-items: flex-start; gap: .45rem; padding: .35rem .4rem; border: 0; border-radius: .35rem; background: none; text-align: start; font-size: .875rem; cursor: pointer; }
        .saft-designer .base-option:hover, .saft-designer .base-option:focus-visible { background: var(--gray-100); outline: none; }
        .dark .saft-designer .base-option:hover, .dark .saft-designer .base-option:focus-visible { background: var(--gray-800); }
        .saft-designer .base-option[aria-selected="true"] .label { font-weight: 600; }
        .saft-designer .base-option:disabled { opacity: .45; cursor: not-allowed; }
        .saft-designer .base-option:disabled:hover { background: none; }
        .saft-designer .base-option.reset { color: var(--gray-600); }
        .saft-designer .base-divider { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--gray-500); padding: .55rem .4rem .2rem; border-top: 1px solid var(--gray-200); margin-top: .3rem; }
        .dark .saft-designer .base-divider { border-color: var(--gray-700); }
        .saft-designer .base-option .label, .saft-designer .base-option .sub { display: block; overflow-wrap: anywhere; }
        .saft-designer .base-option .sub { font-size: .75rem; color: var(--gray-500); }
        .saft-designer .base-icon { width: 1.1rem; height: 1.1rem; flex: none; margin-top: .05rem; }
        .saft-designer .base-icon.internal { color: var(--primary-600); }
        .saft-designer .base-icon.external { color: var(--gray-400); }
        .saft-designer .base-icon.snapshot { color: var(--success-600); }
        .saft-designer .base-none, .saft-designer .base-loading { font-size: .8rem; color: var(--gray-500); padding: .35rem .25rem 0; }
    </style>

    <script>
        // Ein Bereich (oder der Feldkatalog) als Sortable-Liste. Livewire zeichnet danach neu; das DOM wird
        // vorher zurückgesetzt, damit Livewire nicht über verschobene Knoten stolpert.
        window.saftReportList ??= function (el, wire) {
            const zone = el.dataset.zone
            const catalog = zone === 'catalog'

            return window.Sortable.create(el, {
                group: { name: 'saft-report', pull: catalog ? 'clone' : true, put: ! catalog },
                sort: ! catalog,
                draggable: '[data-item]',
                handle: catalog ? undefined : '[data-handle]',
                forceFallback: true,
                fallbackOnBody: true,
                animation: 150,
                onAdd(event) {
                    const { item, from, oldDraggableIndex, newDraggableIndex } = event
                    const fromZone = from.dataset.zone

                    if (fromZone === 'catalog') {
                        item.remove()
                        wire.add(zone, item.dataset.ref, newDraggableIndex)
                    } else {
                        from.insertBefore(item, from.children[oldDraggableIndex] ?? null)
                        wire.moveBetween(fromZone, oldDraggableIndex, zone, newDraggableIndex)
                    }
                },
                onUpdate(event) {
                    const { item, from, oldDraggableIndex, newDraggableIndex } = event
                    const reference = from.children[oldDraggableIndex + (oldDraggableIndex > newDraggableIndex ? 1 : 0)] ?? null
                    from.insertBefore(item, reference)
                    wire.move(zone, oldDraggableIndex, newDraggableIndex)
                },
            })
        }
    </script>

    <div>{{ $this->form }}</div>

    @if ($assistantAvailable)
        @include('filament.reports.assistant')
    @endif

    <div class="saft-designer" data-report-designer>
        {{-- Felder ----------------------------------------------------------------------------- --}}
        <section class="pane" aria-label="{{ __('reports.ui.fields') }}">
            <h3>{{ __('reports.ui.base') }}</h3>
            @php
                $selectedBase = collect($baseTables)->firstWhere('id', $state['base'] ?? null);
                $baseIcon = fn (array $t): array => match ($t['kind']) {
                    \App\Reports\Editor\FieldCatalog::KIND_INTERNAL => ['heroicon-o-circle-stack', 'internal', __('reports.ui.base_kinds.internal')],
                    \App\Reports\Editor\FieldCatalog::KIND_SNAPSHOT => ['heroicon-o-cloud-arrow-down', 'snapshot', __('reports.ui.base_kinds.snapshot', ['time' => $t['snapshot_at']?->timezone(config('app.timezone'))->format('d.m.Y H:i')])],
                    default => ['heroicon-o-cloud', 'external', __('reports.ui.base_kinds.external')],
                };
            @endphp
            {{-- Auswahlliste mit Suchfeld; Icon zeigt SAFT-Tabelle bzw. angebunden ohne/mit Datenstand. --}}
            <div class="base-picker" id="designer-base" data-base-picker
                 x-data="{
                     open: false,
                     q: '',
                     toggle() { this.open ? this.close() : this.show() },
                     show() { this.open = true; this.q = ''; this.$nextTick(() => this.$refs.filter.focus()) },
                     close() { this.open = false },
                     visible() { return [...this.$refs.list.querySelectorAll('[data-base-option]:not([disabled]), [data-base-reset]')].filter((el) => el.offsetParent !== null) },
                     matches(el) { return this.q.trim() === '' || (el.dataset.search ?? '').includes(this.q.trim().toLowerCase()) },
                     move(el, step) { const items = this.visible(); const i = items.indexOf(el); (items[i + step] ?? (step < 0 ? this.$refs.filter : null))?.focus() },
                     pick(id) { this.close(); $wire.setBase(id) },
                 }"
                 x-on:keydown.escape.prevent.stop="close(); $refs.button.focus()"
                 x-on:click.outside="close()">
                <button type="button" class="base-button" x-ref="button" x-on:click="toggle()" aria-haspopup="listbox" x-bind:aria-expanded="open" aria-label="{{ __('reports.ui.base') }}">
                    @if ($selectedBase)
                        @php [$icon, $class, $title] = $baseIcon($selectedBase); @endphp
                        <x-filament::icon :icon="$icon" class="base-icon {{ $class }}" title="{{ $title }}" />
                        <span class="grow" data-base-selected>{{ $selectedBase['label'] }}</span>
                    @else
                        <span class="grow placeholder">{{ __('reports.ui.choose_base') }}</span>
                    @endif
                    <x-filament::icon icon="heroicon-m-chevron-down" class="base-chevron" />
                </button>
                <div class="base-panel" x-show="open" x-cloak x-transition.opacity>
                    <input class="search" type="search" x-ref="filter" x-model="q" data-base-filter
                           placeholder="{{ __('reports.ui.base_filter') }}" aria-label="{{ __('reports.ui.base_filter') }}"
                           x-on:keydown.down.prevent="visible()[0]?.focus()"
                           x-on:keydown.enter.prevent="visible().length === 1 && visible()[0].click()">
                    @php
                        // Ist eine Grundtabelle gewählt, sind nur die über Verknüpfungen erreichbaren wählbar.
                        $baseGroups = $reachable === null
                            ? [[null, $baseTables]]
                            : [
                                [null, collect($baseTables)->filter(fn (array $t): bool => in_array($t['id'], $reachable, true))->values()->all()],
                                [__('reports.ui.base_unlinked', ['table' => $selectedBase['label'] ?? '']), collect($baseTables)->reject(fn (array $t): bool => in_array($t['id'], $reachable, true))->values()->all()],
                            ];
                    @endphp
                    <ul x-ref="list" role="listbox" aria-label="{{ __('reports.ui.base') }}">
                        @if ($reachable !== null)
                            <li x-show="q.trim() === ''" data-search="">
                                <button type="button" class="base-option reset" data-base-reset x-on:click="pick(null)"
                                        x-on:keydown.down.prevent="move($el, 1)" x-on:keydown.up.prevent="move($el, -1)">
                                    <x-filament::icon icon="heroicon-o-x-mark" class="base-icon" />
                                    <span class="grow"><span class="label">{{ __('reports.ui.base_reset') }}</span></span>
                                </button>
                            </li>
                        @endif
                        @foreach ($baseGroups as [$heading, $tables])
                            @if ($heading !== null && $tables !== [])
                                <li class="base-divider" role="presentation" x-show="[...$el.parentElement.querySelectorAll('[data-unlinked]')].some((li) => matches(li))">{{ $heading }}</li>
                            @endif
                            @foreach ($tables as $table)
                                @php [$icon, $class, $title] = $baseIcon($table); $locked = $heading !== null; @endphp
                                <li x-show="matches($el)" data-search="{{ mb_strtolower($table['label'].' '.$table['name'].' '.($table['connection'] ?? '')) }}" @if ($locked) data-unlinked @endif>
                                    <button type="button" role="option" class="base-option" data-base-option="{{ $table['id'] }}" data-kind="{{ $class }}"
                                            aria-selected="{{ ($state['base'] ?? null) === $table['id'] ? 'true' : 'false' }}"
                                            @if ($locked)
                                                disabled aria-disabled="true" title="{{ __('reports.ui.base_unlinked_help', ['table' => $selectedBase['label'] ?? '']) }}"
                                            @else
                                                x-on:click="pick({{ $table['id'] }})"
                                            @endif
                                            x-on:keydown.down.prevent="move($el, 1)" x-on:keydown.up.prevent="move($el, -1)">
                                        <x-filament::icon :icon="$icon" class="base-icon {{ $class }}" />
                                        <span class="grow">
                                            <span class="label">{{ $table['label'] }}</span>
                                            @if ($table['kind'] !== \App\Reports\Editor\FieldCatalog::KIND_INTERNAL)
                                                <span class="sub">{{ $table['connection'] ? $table['connection'].' · ' : '' }}{{ $title }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                    <p class="base-none" x-show="q.trim() !== '' && ! [...$refs.list.querySelectorAll('li[data-search]')].some((li) => li.dataset.search !== '' && matches(li))">{{ __('reports.ui.base_none') }}</p>
                </div>
            </div>
            <p class="base-loading" wire:loading wire:target="setBase, mountAction, callMountedAction">{{ __('reports.ui.snapshot_creating') }}</p>

            @if (filled($state['base'] ?? null))
                <h3>{{ __('reports.ui.fields') }}</h3>
                <input class="search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('reports.ui.search_field') }}" aria-label="{{ __('reports.ui.search_field') }}">
                <div class="catalog">
                    @foreach ($catalog as $group => $entries)
                        <h3>{{ $group }}</h3>
                        <ul data-zone="catalog" x-data x-init="saftReportList($el, $wire)" wire:key="catalog-{{ md5($group.$this->search) }}">
                            @foreach ($entries as $entry)
                                <li class="chip" data-item data-ref="{{ $entry['ref'] }}" data-field="{{ $entry['label'] }}" wire:key="field-{{ $entry['ref'] }}" style="cursor: grab">
                                    <span class="kind" aria-hidden="true">{{ $kindIcon[$entry['kind']] ?? '' }}</span>
                                    <span class="grow" title="{{ $entry['label'] }}">{{ $entry['name'] }}</span>
                                    <x-filament::dropdown placement="bottom-end">
                                        <x-slot name="trigger">
                                            <button type="button" class="icon-btn" aria-label="{{ __('reports.ui.add_to', ['field' => $entry['label']]) }}" title="{{ __('reports.ui.add_to', ['field' => $entry['label']]) }}">＋</button>
                                        </x-slot>
                                        <x-filament::dropdown.list>
                                            @foreach ($zones as $zone => $zoneLabel)
                                                <x-filament::dropdown.list.item wire:click="add('{{ $zone }}', '{{ $entry['ref'] }}')" x-on:click="close()">
                                                    {{ $zoneLabel }}
                                                </x-filament::dropdown.list.item>
                                            @endforeach
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Vorschau ----------------------------------------------------------------------------- --}}
        <section class="pane" aria-label="{{ __('reports.ui.preview') }}">
            <div class="visuals" role="group" aria-label="{{ __('reports.ui.visual') }}">
                @foreach ($visuals as $type => $reason)
                    <x-filament::button
                        size="sm"
                        :color="$state['visual'] === $type ? 'primary' : 'gray'"
                        :outlined="$state['visual'] !== $type"
                        :disabled="$reason !== null"
                        :tooltip="$reason"
                        wire:click="setVisual('{{ $type }}')"
                        data-visual="{{ $type }}"
                    >
                        {{ __('reports.visuals.' . $type) }}
                    </x-filament::button>
                @endforeach
            </div>

            @if ($sentence)
                <p class="sentence" data-report-sentence>{{ $sentence }}</p>
            @endif

            @if (! $hasContent)
                <p class="hint">{{ filled($state['base'] ?? null) ? __('reports.ui.empty_hint') : __('reports.ui.base_hint') }}</p>
            @else
                @include('filament.reports.result', ['sortTokens' => $sortTokens, 'currentSort' => $state['sort'] ?? null, 'currentDir' => $state['sortDir'] ?? 'asc'])
            @endif
        </section>

        {{-- Aufbau -------------------------------------------------------------------------------- --}}
        <section class="pane" aria-label="{{ __('reports.ui.structure') }}">
            @foreach ($zones as $zone => $zoneLabel)
                <h3 id="zone-{{ $zone }}">{{ $zoneLabel }}</h3>
                <ul class="zone" data-zone="{{ $zone }}" aria-labelledby="zone-{{ $zone }}" data-empty="{{ __('reports.ui.drop_here') }}" x-data x-init="saftReportList($el, $wire)" wire:key="zone-{{ $zone }}">@foreach ($state[$zone] as $i => $item)@php($isFormula = is_array($item['expr'] ?? null))@php($isCalc = $zone === 'values' && ($item['agg'] ?? null) === \App\Reports\ReportDefinition::CALC)<li class="chip @if ((filled($item['ref'] ?? null) && ! isset($labels[$item['ref']])) || ($isFormula && $zone === 'rows' && $grouped)) problem @endif" data-item data-ref="{{ $item['ref'] ?? '' }}" wire:key="{{ $zone }}-{{ $i }}-{{ $item['ref'] ?? ($isCalc ? 'calc' : ($isFormula ? 'formula' : 'count')) }}" @if ($isFormula && $zone === 'rows' && $grouped) title="{{ __('reports.ui.formula.list_only') }}" @endif>
                        <span class="handle" data-handle aria-hidden="true">⠿</span>
                        <span class="grow" title="{{ $isCalc ? ($this->valueLabels()[$item['id'] ?? ''] ?? '') : ($isFormula ? $this->formulaText($item['expr']) : ($labels[$item['ref'] ?? ''] ?? '')) }}">
                            @if ($isCalc)
                                <span aria-hidden="true">Σƒ</span> {{ $item['label'] ?? ($this->valueLabels()[$item['id'] ?? ''] ?? '') }}
                            @elseif ($isFormula)
                                <span aria-hidden="true">ƒ</span> {{ $item['label'] ?? $this->formulaText($item['expr']) }}
                            @elseif ($zone === 'values')
                                {{ $item['label'] ?? (($item['ref'] ?? null) === null ? __('reports.describe.records') : ($labels[$item['ref']] ?? __('reports.ui.unknown_field'))) }}
                            @elseif ($zone === 'filters')
                                {{ ($labels[$item['ref']] ?? __('reports.ui.unknown_field')) . ' ' . __('reports.operators.' . ($item['op'] ?? 'eq')) . (in_array($item['op'] ?? '', ['between', 'in'], true) ? ' …' : (filled($item['value'] ?? null) ? ' ' . $item['value'] : '')) }}
                            @else
                                {{ $item['label'] ?? ($labels[$item['ref']] ?? __('reports.ui.unknown_field')) }}
                            @endif
                        </span>

                        @if ($zone === 'values' && $isFormula)
                            <select wire:change="setAggregate({{ $i }}, $event.target.value)" aria-label="{{ __('reports.ui.aggregate') }}">
                                @foreach (['sum', 'avg', 'min', 'max'] as $agg)
                                    <option value="{{ $agg }}" @selected($item['agg'] === $agg)>{{ __('reports.aggregates.' . $agg) }}</option>
                                @endforeach
                            </select>
                        @elseif ($zone === 'values' && filled($item['ref'] ?? null))
                            <select wire:change="setAggregate({{ $i }}, $event.target.value)" aria-label="{{ __('reports.ui.aggregate') }}">
                                @foreach (\App\Reports\ReportDefinition::AGGREGATES as $agg)
                                    @if ($agg === 'count' || $agg === 'count_distinct' || ($kinds[$item['ref']] ?? '') === 'number' || (in_array($agg, ['min', 'max'], true) && ($kinds[$item['ref']] ?? '') === 'date'))
                                        <option value="{{ $agg }}" @selected($item['agg'] === $agg)>{{ __('reports.aggregates.' . $agg) }}</option>
                                    @endif
                                @endforeach
                            </select>
                        @endif

                        @if (in_array($zone, ['rows', 'columns'], true) && $grouped && ($kinds[$item['ref'] ?? ''] ?? '') === 'date')
                            <select wire:change="setBucket('{{ $zone }}', {{ $i }}, $event.target.value)" aria-label="{{ __('reports.ui.bucket') }}">
                                <option value="">{{ __('reports.ui.bucket_none') }}</option>
                                @foreach (\App\Reports\ReportDefinition::BUCKETS as $bucket)
                                    <option value="{{ $bucket }}" @selected(($item['bucket'] ?? null) === $bucket)>{{ __('reports.buckets.' . $bucket) }}</option>
                                @endforeach
                            </select>
                        @endif

                        @if ($isCalc)
                            <button type="button" class="icon-btn" wire:click="mountAction('calc', { index: {{ $i }} })" aria-label="{{ __('reports.ui.formula.edit') }}" title="{{ __('reports.ui.formula.edit') }}">✎</button>
                        @elseif ($isFormula)
                            <button type="button" class="icon-btn" wire:click="mountAction('formula', { zone: '{{ $zone }}', index: {{ $i }} })" aria-label="{{ __('reports.ui.formula.edit') }}" title="{{ __('reports.ui.formula.edit') }}">✎</button>
                        @elseif ($zone === 'filters')
                            <button type="button" class="icon-btn" wire:click="mountAction('filter', { index: {{ $i }} })" aria-label="{{ __('reports.ui.edit_filter') }}" title="{{ __('reports.ui.edit_filter') }}">✎</button>
                        @elseif (in_array($zone, ['rows', 'values'], true))
                            <button type="button" class="icon-btn" wire:click="mountAction('label', { zone: '{{ $zone }}', index: {{ $i }} })" aria-label="{{ __('reports.ui.label') }}" title="{{ __('reports.ui.label') }}">✎</button>
                        @endif
                        <button type="button" class="icon-btn" wire:click="remove('{{ $zone }}', {{ $i }})" aria-label="{{ __('reports.ui.remove') }}" title="{{ __('reports.ui.remove') }}">✕</button>
                    </li>@endforeach</ul>

                @if ($zone === 'values' && filled($state['base'] ?? null))
                    <div style="display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-top: .3rem">
                        <x-filament::link tag="button" size="sm" wire:click="addCount" icon="heroicon-m-plus">
                            {{ __('reports.ui.add_count') }}
                        </x-filament::link>
                        <x-filament::link tag="button" size="sm" wire:click="mountAction('formula', { zone: 'values' })" icon="heroicon-m-plus">
                            {{ __('reports.ui.formula.value') }}
                        </x-filament::link>
                        @if (collect($state['values'])->contains(fn (array $v): bool => ($v['agg'] ?? null) !== \App\Reports\ReportDefinition::CALC))
                            <x-filament::link tag="button" size="sm" wire:click="mountAction('calc')" icon="heroicon-m-plus">
                                {{ __('reports.ui.calc.title') }}
                            </x-filament::link>
                        @endif
                    </div>
                @elseif ($zone === 'rows' && ! $grouped && filled($state['base'] ?? null))
                    <x-filament::link tag="button" size="sm" wire:click="mountAction('formula', { zone: 'rows' })" icon="heroicon-m-plus" style="margin-top: .3rem">
                        {{ __('reports.ui.formula.column') }}
                    </x-filament::link>
                @endif
            @endforeach

            <h3>{{ __('reports.ui.limit') }}</h3>
            <input class="search" type="number" min="1" max="{{ config('reports.max_rows') }}" wire:model.blur="state.limit" placeholder="{{ config('reports.max_rows') }}" aria-label="{{ __('reports.ui.limit') }}">
        </section>
    </div>
</x-filament-panels::page>
