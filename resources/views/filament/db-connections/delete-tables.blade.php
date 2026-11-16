{{-- Dialog „Verbindung und Anbindungen löschen“: betroffene Tabellen. --}}
<div class="saft-delete-connection" style="display: flex; flex-direction: column; gap: 1rem;">
    <p style="font-size: .875rem;">{{ $description }}</p>

    <div style="overflow: auto; max-height: 20rem;">
        <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid var(--gray-200);">
                    <th style="padding: .5rem;">{{ __('db_connections.delete.columns.name') }}</th>
                    <th style="padding: .5rem;">{{ __('db_connections.delete.columns.label') }}</th>
                    <th style="padding: .5rem;">{{ __('db_connections.delete.columns.external') }}</th>
                    <th style="padding: .5rem;">{{ __('db_connections.delete.columns.kind') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tables as $table)
                    <tr style="border-bottom: 1px solid var(--gray-100);" data-affected-table="{{ $table->name }}">
                        <td style="padding: .5rem; font-family: monospace;">{{ $table->name }}</td>
                        <td style="padding: .5rem;">{{ $table->label }}</td>
                        <td style="padding: .5rem; font-family: monospace;">{{ $table->external_schema }}.{{ $table->external_table }}</td>
                        <td style="padding: .5rem;">{{ __('db_connections.delete.kind.'.($table->external_kind === 'view' ? 'view' : 'table')) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @unless ($allowed)
        <x-filament::callout color="danger" icon="heroicon-o-lock-closed">
            <x-slot name="description">{{ __('db_connections.delete.not_allowed') }}</x-slot>
        </x-filament::callout>
    @endunless
</div>
