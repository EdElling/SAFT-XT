{{-- Massenverknüpfung: Fortschritt, Ergebnis je Tabelle, Auswahltabelle (App\Livewire\BulkConnectTables). --}}
<div class="saft-bulk-connect" style="display: flex; flex-direction: column; gap: 1.5rem;">
    @if ($queue !== [])
        @php($done = $total - count($queue))
        <x-filament::section wire:poll.750ms="processNext" data-bulk-progress>
            <x-slot name="heading">{{ __('table_connector.bulk.progress', ['done' => $done, 'total' => $total]) }}</x-slot>
            <div style="height: .5rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden;">
                <div style="height: 100%; width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%; background: var(--primary-600); transition: width .3s;"></div>
            </div>
        </x-filament::section>
    @endif

    @if ($results !== [])
        <x-filament::section collapsible data-bulk-results>
            <x-slot name="heading">{{ __('table_connector.bulk.results') }}</x-slot>
            @if ($queue === [])
                <x-slot name="afterHeader">
                    <x-filament::button color="gray" size="sm" wire:click="clearResults">{{ __('table_connector.bulk.clear') }}</x-filament::button>
                </x-slot>
            @endif

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid var(--gray-200);">
                            <th style="padding: .5rem;">{{ __('table_connector.bulk.columns.table') }}</th>
                            <th style="padding: .5rem;">{{ __('table_connector.bulk.columns.proposed') }}</th>
                            <th style="padding: .5rem;">{{ __('table_connector.bulk.columns.result') }}</th>
                            <th style="padding: .5rem;">{{ __('table_connector.bulk.columns.note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $result)
                            <tr style="border-bottom: 1px solid var(--gray-100);" data-bulk-result="{{ $result['table'] }}" data-status="{{ $result['status'] }}">
                                <td style="padding: .5rem; font-family: monospace;">{{ $result['table'] }}</td>
                                <td style="padding: .5rem; font-family: monospace;">{{ $result['name'] }}</td>
                                <td style="padding: .5rem;">
                                    <x-filament::badge :color="match ($result['status']) {
                                        \App\ExternalData\BulkConnector::CONNECTED => 'success',
                                        \App\ExternalData\BulkConnector::READ_ONLY => 'warning',
                                        \App\ExternalData\BulkConnector::SKIPPED => 'gray',
                                        default => 'danger',
                                    }">{{ __('table_connector.bulk.status.'.$result['status']) }}</x-filament::badge>
                                </td>
                                <td style="padding: .5rem;">{{ $result['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
