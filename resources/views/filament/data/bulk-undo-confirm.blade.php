{{-- Bestätigung „Rückgängig“ (B-133 S3, App\DynamicData\Ui\BulkUndoActions::undo) mit Vorschau (previewData()). --}}
<div class="saft-bulk-undo" data-bulk-undo-confirm>
    <style>
        .saft-bulk-undo { display: grid; gap: .5rem; font-size: .875rem; text-align: left; }
        .saft-bulk-undo .muted { color: var(--gray-500); }
        .saft-bulk-undo ul { list-style: disc; padding-left: 1.25rem; display: grid; gap: .25rem; color: var(--gray-600); }
        .dark .saft-bulk-undo ul { color: var(--gray-400); }
        .saft-bulk-undo table { width: 100%; border-collapse: collapse; }
        .saft-bulk-undo th, .saft-bulk-undo td { text-align: left; padding: .25rem .5rem; border-bottom: 1px solid var(--gray-200); vertical-align: top; }
        .dark .saft-bulk-undo th, .dark .saft-bulk-undo td { border-color: var(--gray-700); }
        .saft-bulk-undo th { font-weight: 500; color: var(--gray-500); }
        .saft-bulk-undo .now { color: var(--gray-500); text-decoration: line-through; }
        .saft-bulk-undo tr.stays td { color: var(--gray-500); }
        .saft-bulk-undo .warn { color: var(--warning-700); }
        .dark .saft-bulk-undo .warn { color: var(--warning-400); }
    </style>
    <p><strong>{{ $what }}</strong></p>
    <p class="muted">{{ trans_choice('data.bulk.undo.meta', $count, ['count' => $count, 'who' => $who, 'when' => $when]) }}</p>
    <p><x-filament::link :href="$records" icon="heroicon-o-funnel" color="gray" data-bulk-records-confirm>{{ __('data.bulk.view.show') }}</x-filament::link></p>

    @if ($preview !== null)
        @php($counts = $preview['counts'])
        @if ($preview['partial'])
            <p class="muted" data-bulk-undo-partial>{{ __('data.bulk.undo.preview_partial', ['scan' => $preview['scan'], 'count' => $preview['total']]) }}</p>
        @endif
        <p data-bulk-undo-counts>
            <strong>{{ trans_choice('data.bulk.undo.preview_revert', $counts['revert'], ['count' => $counts['revert']]) }}</strong>
            @foreach (['already' => 'preview_already', 'changed' => 'preview_changed', 'not_logged' => 'preview_not_logged', 'gone' => 'preview_gone'] as $key => $text)
                @if ($counts[$key] > 0)
                    <span class="{{ $key === 'already' ? 'muted' : 'warn' }}">{{ trans_choice('data.bulk.undo.'.$text, $counts[$key], ['count' => $counts[$key]]) }}</span>
                @endif
            @endforeach
        </p>
        @if ($preview['rows'] !== [])
            <table data-bulk-undo-preview>
                <thead>
                    <tr>
                        <th>{{ __('data.bulk.undo.record') }}</th>
                        @foreach ($preview['columns'] as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                        <th>{{ __('data.bulk.undo.status_heading') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        <tr class="{{ $row['status'] === 'revert' ? '' : 'stays' }}" data-bulk-undo-row="{{ $row['status'] }}">
                            <td>{{ $row['title'] }}</td>
                            @foreach ($row['cells'] as $cell)
                                <td>
                                    @if ($row['status'] === 'revert' && $cell['now'] !== $cell['after'])
                                        <span class="now">{{ $cell['now'] }}</span> → <strong>{{ $cell['after'] }}</strong>
                                    @else
                                        {{ $cell['now'] }}
                                    @endif
                                </td>
                            @endforeach
                            <td>{{ __('data.bulk.undo.status.'.($row['status'] === 'revert' ? 'revert_'.$preview['operation'] : $row['status'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (min($preview['total'], $preview['scan']) > count($preview['rows']))
                <p class="muted">{{ trans_choice('data.bulk.preview_more', min($preview['total'], $preview['scan']) - count($preview['rows']), ['count' => min($preview['total'], $preview['scan']) - count($preview['rows'])]) }}</p>
            @endif
        @endif
    @endif

    <p>{{ __('data.bulk.undo.explain') }}</p>
    @if ($hints !== [])
        <ul data-bulk-undo-hints>
            @foreach ($hints as $hint)
                <li>{{ $hint }}</li>
            @endforeach
        </ul>
    @endif
</div>
