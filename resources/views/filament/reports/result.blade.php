{{-- Ergebnis einer Auswertung: Tabelle, Kreuztabelle, Diagramm (Chart.js über Filament) oder Kennzahl.
     Variablen: $result (?ReportResult), $problems (list<string>), $visual, $formatter, $chart (?array). --}}
@php
    use Filament\Support\Facades\FilamentAsset;
@endphp
<div class="saft-report" data-report-visual="{{ $visual }}">
    <style>
        .saft-report { display: flex; flex-direction: column; gap: .75rem; }
        .saft-report-scroll { overflow-x: auto; }
        .saft-report table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .saft-report th, .saft-report td { padding: .5rem .75rem; border-bottom: 1px solid var(--gray-200); text-align: start; white-space: nowrap; }
        .saft-report th { font-weight: 600; color: var(--gray-950); background: var(--gray-50); }
        .saft-report td.num, .saft-report th.num { text-align: end; font-variant-numeric: tabular-nums; }
        .saft-report .note { font-size: .875rem; color: var(--gray-600); }
        .saft-report .errors { border: 1px solid var(--danger-300, #fca5a5); background: var(--danger-50, #fef2f2); color: var(--danger-800, #991b1b); border-radius: .5rem; padding: .75rem 1rem; }
        .saft-report .errors ul { list-style: disc; padding-inline-start: 1.25rem; margin: .25rem 0 0; }
        .saft-report .kpi { font-size: 2.5rem; font-weight: 700; line-height: 1.1; color: var(--gray-950); }
        .saft-report .kpi-label { color: var(--gray-600); }
        .saft-report .chart { position: relative; height: 22rem; }
        .dark .saft-report th { color: var(--gray-50); background: color-mix(in oklab, var(--gray-800) 60%, transparent); }
        .dark .saft-report th, .dark .saft-report td { border-color: var(--gray-700); }
        .dark .saft-report .note, .dark .saft-report .kpi-label { color: var(--gray-400); }
        .dark .saft-report .kpi { color: var(--gray-50); }
        .dark .saft-report .errors { background: color-mix(in oklab, var(--danger-500, #ef4444) 15%, transparent); color: var(--danger-200, #fecaca); border-color: var(--danger-700, #b91c1c); }
    </style>

    @if ($problems !== [])
        <div class="errors" role="alert" data-report-errors>
            <strong>{{ __('reports.ui.cannot_run') }}</strong>
            <ul>
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @elseif ($result !== null)
        @if ($result->rows === [])
            <p class="note" data-report-empty>{{ __('reports.ui.no_rows') }}</p>
        @elseif ($visual === 'kpi')
            @php $measure = collect($result->columns)->firstWhere('role', \App\Reports\ReportColumn::MEASURE); @endphp
            <div data-report-kpi>
                <div class="kpi">{{ $formatter->format($result->rows[0][$measure->key] ?? null, $measure) }}</div>
                <div class="kpi-label">{{ $measure->label }}</div>
            </div>
        @elseif (in_array($visual, ['bar', 'line', 'pie'], true) && $chart !== null)
            <div
                class="chart fi-wi-chart"
                x-load
                x-load-src="{{ FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                wire:key="report-chart-{{ md5(json_encode($chart)) }}"
                data-chart-type="{{ $visual }}"
                x-data="chart({
                    cachedData: @js($chart),
                    options: @js(['maintainAspectRatio' => false, 'plugins' => ['legend' => ['display' => count($chart['datasets']) > 1 || $visual === 'pie']]]),
                    type: @js($visual),
                })"
            >
                <canvas x-ref="canvas" aria-label="{{ __('reports.visuals.' . $visual) }}" role="img" style="width: 100%; height: 100%"></canvas>
                <span aria-hidden="true" x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span aria-hidden="true" x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span aria-hidden="true" x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span aria-hidden="true" x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                <span aria-hidden="true" x-ref="tooltipBackgroundColorElement" class="fi-wi-chart-tooltip-bg-color"></span>
                <span aria-hidden="true" x-ref="tooltipTextColorElement" class="fi-wi-chart-tooltip-text-color"></span>
                <span aria-hidden="true" x-ref="tooltipBorderColorElement" class="fi-wi-chart-tooltip-border-color"></span>
            </div>
        @elseif (($cross = $result->crossTab()) !== null && $visual !== 'table')
            <div class="saft-report-scroll">
                <table data-report-crosstab>
                    <thead>
                        <tr>
                            @foreach ($cross->rowColumns as $column)
                                <th>{{ $column->label }}</th>
                            @endforeach
                            @foreach ($cross->columnValues as $value)
                                @foreach ($cross->measures as $measure)
                                    <th class="num">
                                        {{ $formatter->format($value, $cross->column) ?: __('reports.ui.empty_value') }}@if (count($cross->measures) > 1) · {{ $measure->label }}@endif
                                    </th>
                                @endforeach
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cross->rows as $row)
                            <tr>
                                @foreach ($cross->rowColumns as $i => $column)
                                    <td>{{ $formatter->format($row['keys'][$i], $column) }}</td>
                                @endforeach
                                @foreach ($cross->columnValues as $value)
                                    @foreach ($cross->measures as $measure)
                                        <td class="num">{{ $formatter->format($cross->cell($row, $value, $measure->key), $measure) }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($cross->truncatedColumns)
                <p class="note">{{ __('reports.ui.columns_truncated', ['max' => config('reports.max_crosstab_columns')]) }}</p>
            @endif
        @else
            <div class="saft-report-scroll">
                <table data-report-table>
                    <thead>
                        <tr>
                            @foreach ($result->columns as $column)
                                <th @class(['num' => $column->isNumeric()]) @if (isset($sortTokens[$column->key])) aria-sort="{{ ($currentSort ?? null) === $sortTokens[$column->key] ? (($currentDir ?? 'asc') === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
                                    @if (isset($sortTokens[$column->key]))
                                        {{-- Im Editor: Klick sortiert (aufsteigend, absteigend, Vorgabe). --}}
                                        <button type="button" wire:click="sortBy('{{ $sortTokens[$column->key] }}')" style="font: inherit; color: inherit; background: none; border: 0; padding: 0; cursor: pointer">
                                            {{ $column->label }}
                                            @if (($currentSort ?? null) === $sortTokens[$column->key]) {{ ($currentDir ?? 'asc') === 'asc' ? '▲' : '▼' }} @endif
                                        </button>
                                    @else
                                        {{ $column->label }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->rows as $row)
                            <tr>
                                @foreach ($result->columns as $column)
                                    <td @class(['num' => $column->isNumeric()])>{{ $formatter->format($row[$column->key], $column) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($result->truncated)
            <p class="note" data-report-truncated>{{ $truncatedText ?? __('reports.ui.truncated', ['count' => number_format(count($result->rows), 0, ',', '\'')]) }}</p>
        @endif

        @foreach ($result->report->snapshots as $snapshotTableId => $snapshot)
            <p class="note" data-report-snapshot="{{ $snapshotTableId }}">{{ __('snapshots.as_of', [
                'table' => $result->report->tables[$snapshotTableId]->label,
                'connection' => $result->report->tables[$snapshotTableId]->databaseConnection?->name,
                'time' => $snapshot->finished_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            ]) }}</p>
        @endforeach
    @endif
</div>
