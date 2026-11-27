{{-- Vorschau der Sammeländerung (B-133, App\DynamicData\Ui\BulkRecordActions::previewData): Anzahl, Beispiele vorher → nachher, Hinweise. --}}
<div class="saft-bulk-preview" data-bulk-preview>
    <style>
        .saft-bulk-preview { display: grid; gap: .75rem; font-size: .875rem; }
        .saft-bulk-preview h3 { font-weight: 600; color: var(--gray-950); }
        .dark .saft-bulk-preview h3 { color: var(--color-white, #fff); }
        .saft-bulk-preview .muted { color: var(--gray-500); }
        .saft-bulk-preview .warn { color: var(--warning-700); }
        .dark .saft-bulk-preview .warn { color: var(--warning-400); }
        .saft-bulk-preview .danger { color: var(--danger-600); font-weight: 500; }
        .saft-bulk-preview table { width: 100%; border-collapse: collapse; }
        .saft-bulk-preview th, .saft-bulk-preview td { text-align: left; padding: .25rem .5rem; border-bottom: 1px solid var(--gray-200); vertical-align: top; }
        .dark .saft-bulk-preview th, .dark .saft-bulk-preview td { border-color: var(--gray-700); }
        .saft-bulk-preview th { font-weight: 500; color: var(--gray-500); }
        .saft-bulk-preview .before { color: var(--gray-500); text-decoration: line-through; }
        .saft-bulk-preview .same { color: var(--gray-500); }
        .saft-bulk-preview ul { list-style: disc; padding-left: 1.25rem; display: grid; gap: .25rem; }
    </style>

    <h3>{{ __('data.bulk.preview') }}</h3>

    @if ($tooMany)
        <p class="danger" data-bulk-too-many>{{ __('data.bulk.errors.too_many', ['count' => $count, 'max' => $max]) }}</p>
    @elseif ($empty)
        <p class="muted">{{ __('data.bulk.preview_empty') }}</p>
    @else
        <p>{{ trans_choice('data.bulk.preview_count', $count, ['count' => $count]) }}</p>

        <table data-bulk-examples>
            <thead>
                <tr>
                    <th>#</th>
                    @foreach ($columns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="muted">{{ $row['key'] }}</td>
                        @foreach ($row['cells'] as $cell)
                            <td>
                                @if ($cell['before'] === $cell['after'])
                                    <span class="same">{{ $cell['after'] }} ({{ __('data.bulk.unchanged') }})</span>
                                @else
                                    <span class="before">{{ $cell['before'] }}</span> → <strong>{{ $cell['after'] }}</strong>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($count > count($rows))
            <p class="muted">{{ trans_choice('data.bulk.preview_more', $count - count($rows), ['count' => $count - count($rows)]) }}</p>
        @endif

        @if ($hints !== [] || $background)
            <ul class="warn" data-bulk-hints>
                @foreach ($hints as $hint)
                    <li>{{ $hint }}</li>
                @endforeach
                @if ($background)
                    <li>{{ __('data.bulk.background') }}</li>
                @endif
            </ul>
        @endif

        <p class="muted">{{ __('data.bulk.preview_check') }}</p>
    @endif
</div>
