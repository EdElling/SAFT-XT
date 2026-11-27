{{-- „Letzte Sammeländerungen“ (B-133 S3, App\DynamicData\Ui\BulkUndoActions::history): wer, wann, was, Anzahl, Stand, Rückgängig. --}}
<div class="saft-bulk-history" data-bulk-history>
    <style>
        .saft-bulk-history { font-size: .875rem; }
        .saft-bulk-history table { width: 100%; border-collapse: collapse; }
        .saft-bulk-history th, .saft-bulk-history td { text-align: left; padding: .5rem; border-bottom: 1px solid var(--gray-200); vertical-align: top; }
        .dark .saft-bulk-history th, .dark .saft-bulk-history td { border-color: var(--gray-700); }
        .saft-bulk-history th { font-weight: 500; color: var(--gray-500); }
        .saft-bulk-history .muted { color: var(--gray-500); }
        .saft-bulk-history .num { text-align: right; white-space: nowrap; }
        .saft-bulk-history .nowrap { white-space: nowrap; }
    </style>

    @if ($rows === [])
        <p class="muted">{{ __('data.bulk.undo.history_empty') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ __('data.bulk.undo.when') }}</th>
                    <th>{{ __('data.bulk.undo.who') }}</th>
                    <th>{{ __('data.bulk.undo.what') }}</th>
                    <th class="num">{{ __('data.bulk.undo.count') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr data-bulk-history-row="{{ $row['id'] }}">
                        <td class="nowrap">{{ $row['when'] }}</td>
                        <td>{{ $row['who'] }}</td>
                        <td>
                            {{ $row['what'] }}
                            @if ($row['state'] !== null)
                                <div class="muted" data-bulk-history-state>{{ $row['state'] }}</div>
                            @endif
                        </td>
                        <td class="num">{{ $row['count'] }}</td>
                        <td class="nowrap">
                            @if ($row['undoable'])
                                <x-filament::link
                                    tag="button"
                                    icon="heroicon-o-arrow-uturn-left"
                                    color="warning"
                                    wire:click="mountAction('{{ $action }}', { head: {{ $row['id'] }} })"
                                    data-bulk-undo="{{ $row['id'] }}"
                                >{{ __('data.bulk.undo.action') }}</x-filament::link>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
