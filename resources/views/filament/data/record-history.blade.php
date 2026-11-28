{{-- Verlauf eines Datensatzes (B-174, App\DynamicData\Ui\DynamicRecordActions::history): wann, wer, was je Feld; „Rückgängig“ je Eintrag „Geändert“ (B-173). --}}
<div class="saft-history saft-record-history" data-record-history>
    <style>
        .saft-record-history { font-size: .875rem; }
        .saft-record-history table { width: 100%; border-collapse: collapse; }
        .saft-record-history th, .saft-record-history td { text-align: left; padding: .5rem .75rem; border-bottom: 1px solid var(--gray-200); vertical-align: top; }
        .dark .saft-record-history th, .dark .saft-record-history td { border-color: var(--gray-700); }
        .saft-record-history th { font-weight: 500; color: var(--gray-500); }
        .saft-record-history .muted { color: var(--gray-500); }
        .saft-record-history .nowrap { white-space: nowrap; }
        .saft-record-history .what { white-space: pre-line; }
        .saft-record-history .undo { width: 1%; text-align: right; }
    </style>

    @if ($rows === [])
        <p class="muted">{{ __('data.history.empty') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ __('data.history.when') }}</th>
                    <th>{{ __('data.history.who') }}</th>
                    <th>{{ __('data.history.what') }}</th>
                    <th class="undo"><span class="sr-only">{{ __('data.history.undo.action') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr data-history-entry="{{ $row['id'] }}">
                        <td class="nowrap">{{ $row['when'] }}</td>
                        <td>{{ $row['who'] }}</td>
                        <td class="what">{{ $row['what'] }}</td>
                        <td class="undo">
                            @if ($row['undo'])
                                <x-filament::icon-button
                                    icon="heroicon-o-arrow-uturn-left"
                                    color="warning"
                                    size="sm"
                                    :label="__('data.history.undo.action')"
                                    :tooltip="__('data.history.undo.tooltip')"
                                    wire:click="mountAction('{{ $action }}', { entry: {{ $row['id'] }} })"
                                    data-history-undo="{{ $row['id'] }}"
                                />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
