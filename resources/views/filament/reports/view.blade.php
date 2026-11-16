<x-filament-panels::page>
    <x-filament::section>
        @if ($sentence)
            <p data-report-sentence style="font-style: italic; color: var(--gray-500); margin-bottom: .75rem">{{ $sentence }}</p>
        @endif
        @include('filament.reports.result')
    </x-filament::section>
</x-filament-panels::page>
