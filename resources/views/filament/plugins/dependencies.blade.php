{{-- Dialog „Abhängigkeiten“ auf der Seite Plugins: benötigte Tabellen und Felder mit Zustand. --}}
@php
    $badge = fn (string $state): string => match ($state) { 'ok' => 'success', 'inactive' => 'warning', default => 'danger' };
@endphp
<div class="saft-plugin-dependencies" style="display: flex; flex-direction: column; gap: 1rem;" data-plugin-dependencies>
    <p style="font-size: .875rem;">{{ __('plugins.dependencies.intro') }}</p>

    <div style="overflow: auto; max-height: 28rem;">
        <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid var(--gray-200);">
                    <th style="padding: .5rem;">{{ __('plugins.dependencies.table') }} / {{ __('plugins.dependencies.field') }}</th>
                    <th style="padding: .5rem;">{{ __('common.label') }}</th>
                    <th style="padding: .5rem;">{{ __('plugins.dependencies.state') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tables as $table)
                    <tr style="border-top: 1px solid var(--gray-200); font-weight: 600;" data-dependency-table="{{ $table['name'] }}">
                        <td style="padding: .5rem; font-family: monospace;">{{ $table['name'] }}</td>
                        <td style="padding: .5rem;">{{ $table['label'] ?? '–' }}</td>
                        <td style="padding: .5rem;">
                            <x-filament::badge :color="$badge($table['state'])">{{ __('plugins.dependencies.state_'.$table['state']) }}</x-filament::badge>
                        </td>
                    </tr>
                    @foreach ($table['fields'] as $field)
                        <tr data-dependency-field="{{ $table['name'] }}.{{ $field['name'] }}">
                            <td style="padding: .25rem .5rem .25rem 1.75rem; font-family: monospace;">{{ $field['name'] }}</td>
                            <td style="padding: .25rem .5rem;">{{ $field['label'] ?? '–' }}</td>
                            <td style="padding: .25rem .5rem;">
                                <x-filament::badge :color="$badge($field['state'])">{{ __('plugins.dependencies.state_'.$field['state']) }}</x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>
