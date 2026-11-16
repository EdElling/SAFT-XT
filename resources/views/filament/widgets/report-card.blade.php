<x-filament-widgets::widget>
    @if ($item)
        <x-filament::section data-dashboard-report="{{ $item['report']->id }}">
            <x-slot name="heading">{{ $item['report']->name }}</x-slot>
            <x-slot name="afterHeader">
                <x-filament::link :href="$item['url']" size="sm">{{ __('reports.dashboard.show_all') }}</x-filament::link>
            </x-slot>
            <div style="min-width: 0">
                @include('filament.reports.result', [...$item, 'truncatedText' => __('reports.dashboard.more')])
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
