{{-- Datenrechte als Matrix (Zeile je Tabelle, Spalte je Recht). Zustand: Tabellenname => Rechte.
     Reiter eigene/verknüpfte Tabellen, Suche blendet nur Zeilen aus; „Alle“ je Zeile und je Spalte
     wirkt auf die sichtbaren Zeilen des Reiters. --}}
@php
    $groups = $field->groups();
    $actions = \App\Filament\Forms\Components\DataPermissionMatrix::ACTIONS;
    $labels = collect($actions)->mapWithKeys(fn (string $a): array => [$a => __("roles.data_actions.{$a}")])->all();
    $tabs = array_filter(['own' => $groups['own'], 'external' => $groups['external']], fn (array $g): bool => $g !== []);
    $counts = array_map(fn (array $g): int => array_sum(array_map('count', $g)), $tabs);
    $disabled = $field->isDisabled();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="saft-matrix"
        data-permission-matrix
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            tab: @js(array_key_first($tabs) ?? 'own'),
            search: '',
            rows: @js($groups),
            actions: @js($actions),
            visible(row) { const q = this.search.toLowerCase(); return q === '' || row.label.toLowerCase().includes(q) || row.name.toLowerCase().includes(q) },
            tabRows() { return Object.values(this.rows[this.tab] ?? {}).flat().filter((row) => this.visible(row)) },
            has(table, action) { return ((this.state ?? {})[table] ?? []).includes(action) },
            set(table, actions) { this.state = { ...(this.state ?? {}), [table]: this.actions.filter((a) => actions.includes(a)) } },
            toggle(table, action) {
                const current = (this.state ?? {})[table] ?? []
                this.set(table, current.includes(action) ? current.filter((a) => a !== action) : [...current, action])
            },
            rowAll(row) { return row.actions.every((a) => this.has(row.name, a)) },
            toggleRow(row) { this.set(row.name, this.rowAll(row) ? [] : row.actions) },
            columnAll(action) {
                const rows = this.tabRows().filter((row) => row.actions.includes(action))
                return rows.length > 0 && rows.every((row) => this.has(row.name, action))
            },
            toggleColumn(action) {
                const on = ! this.columnAll(action)
                for (const row of this.tabRows().filter((r) => r.actions.includes(action))) {
                    const current = (this.state ?? {})[row.name] ?? []
                    this.set(row.name, on ? [...new Set([...current, action])] : current.filter((a) => a !== action))
                }
            },
        }"
    >
        <style>
            .saft-matrix .bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between; margin-bottom: .5rem; }
            .saft-matrix .tabs { display: flex; gap: .25rem; }
            .saft-matrix .tabs button { padding: .3rem .75rem; border-radius: .5rem; font-size: .875rem; font-weight: 500; color: var(--gray-600); }
            .saft-matrix .tabs button[aria-selected="true"] { background: var(--primary-50); color: var(--primary-700); }
            .dark .saft-matrix .tabs button[aria-selected="true"] { background: color-mix(in oklab, var(--primary-500) 20%, transparent); color: var(--primary-300); }
            .saft-matrix input[type="search"] { border: 1px solid var(--gray-300); border-radius: .5rem; padding: .3rem .6rem; font-size: .875rem; min-width: 14rem; background: transparent; }
            .saft-matrix table { width: 100%; border-collapse: collapse; font-size: .875rem; }
            .saft-matrix th, .saft-matrix td { padding: .35rem .5rem; border-bottom: 1px solid var(--gray-200); }
            .dark .saft-matrix th, .dark .saft-matrix td { border-color: var(--gray-700); }
            .saft-matrix th { font-weight: 600; text-align: center; white-space: nowrap; }
            .saft-matrix th:first-child, .saft-matrix td:first-child { text-align: start; width: 100%; }
            .saft-matrix td:not(:first-child) { text-align: center; }
            .saft-matrix tr.group td { font-weight: 600; color: var(--gray-500); padding-top: .75rem; }
            .saft-matrix .muted { color: var(--gray-400); }
        </style>

        <div class="bar">
            <div class="tabs" role="tablist">
                @foreach ($tabs as $tab => $unused)
                    <button type="button" role="tab" x-on:click="tab = @js($tab)" x-bind:aria-selected="tab === @js($tab)" data-matrix-tab="{{ $tab }}">
                        {{ __('roles.matrix.tabs.' . $tab) }} ({{ $counts[$tab] }})
                    </button>
                @endforeach
            </div>
            <input type="search" x-model="search" placeholder="{{ __('roles.matrix.search') }}" aria-label="{{ __('roles.matrix.search') }}">
        </div>

        @foreach ($tabs as $tab => $tabGroups)
            <table x-show="tab === @js($tab)" role="tabpanel" data-matrix-panel="{{ $tab }}">
                <thead>
                    <tr>
                        <th scope="col">{{ __('roles.matrix.table') }}</th>
                        <th scope="col">{{ __('roles.matrix.all') }}</th>
                        @foreach ($labels as $action => $label)
                            <th scope="col">{{ $label }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        <td class="muted">{{ __('roles.matrix.whole_column') }}</td>
                        <td></td>
                        @foreach ($actions as $action)
                            <td>
                                <input type="checkbox" class="fi-checkbox-input" x-bind:checked="columnAll(@js($action))" x-on:change="toggleColumn(@js($action))" @disabled($disabled)
                                    aria-label="{{ __('roles.matrix.column_all', ['action' => $labels[$action]]) }}" data-matrix-column="{{ $action }}">
                            </td>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tabGroups as $group => $rows)
                        @if ($group !== '')
                            <tr class="group" x-show="@js($rows).some((row) => visible(row))"><td colspan="{{ 2 + count($actions) }}">{{ $group }}</td></tr>
                        @endif
                        @foreach ($rows as $row)
                            <tr x-show="visible(@js($row))" data-matrix-row="{{ $row['name'] }}">
                                <td>
                                    {{ $row['inactive'] ? __('roles.form.inactive_table', ['table' => $row['label']]) : $row['label'] }}
                                    <span class="muted" style="font-size: .75rem; margin-inline-start: .35rem">{{ $row['name'] }}</span>
                                </td>
                                <td>
                                    <input type="checkbox" class="fi-checkbox-input" x-bind:checked="rowAll(@js($row))" x-on:change="toggleRow(@js($row))" @disabled($disabled)
                                        aria-label="{{ __('roles.matrix.row_all', ['table' => $row['label']]) }}" data-matrix-all="{{ $row['name'] }}">
                                </td>
                                @foreach ($actions as $action)
                                    <td>
                                        @if (in_array($action, $row['actions'], true))
                                            <input type="checkbox" class="fi-checkbox-input" x-bind:checked="has(@js($row['name']), @js($action))" x-on:change="toggle(@js($row['name']), @js($action))" @disabled($disabled)
                                                aria-label="{{ $row['label'] }}: {{ $labels[$action] }}" data-matrix-cell="{{ $row['name'] }}.{{ $action }}">
                                        @else
                                            <span class="muted" title="{{ __('roles.matrix.not_supported') }}">–</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
</x-dynamic-component>
