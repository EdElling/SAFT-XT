{{--
    Stundenplan: Wochenraster mit Zeitachse; je Tag eine Spalte, Kurse als absolut positionierte Blöcke (Höhe nach Dauer,
    überschneidende nebeneinander). Eigene Gestaltung als <style> mit Präfix der Plugin-id (Tailwind-Klassen aus
    Plugin-Views sind im ausgelieferten CSS nicht enthalten). Druck: nur Überschrift, Raster und Legende, A4 quer.
--}}
@php($scale = 1.15)
<x-filament-panels::page>
    <div class="musikschule-planung-form">{{ $this->content }}</div>

    <style>
        .musikschule-planung-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .musikschule-planung-caption { font-weight: 600; font-size: 1.05rem; }
        .musikschule-planung-hint { font-size: .875rem; color: var(--gray-500); }
        .musikschule-planung-warn { font-size: .875rem; color: var(--danger-600); }
        .dark .musikschule-planung-warn { color: var(--danger-400); }
        .musikschule-planung-scroll { overflow-x: auto; }
        .musikschule-planung-grid { display: grid; grid-template-columns: 3.25rem repeat(var(--days), minmax(8.5rem, 1fr)); min-width: calc(3.25rem + var(--days) * 8.5rem);
            border: 1px solid var(--gray-200); border-radius: .75rem; overflow: hidden; background: var(--color-white, #fff); }
        .dark .musikschule-planung-grid { border-color: var(--gray-700); background: var(--gray-900); }
        .musikschule-planung-dayhead { padding: .5rem; text-align: center; font-weight: 600; font-size: .875rem; border-bottom: 1px solid var(--gray-200); border-left: 1px solid var(--gray-200); }
        .dark .musikschule-planung-dayhead { border-color: var(--gray-700); }
        .musikschule-planung-corner { border-bottom: 1px solid var(--gray-200); }
        .dark .musikschule-planung-corner { border-color: var(--gray-700); }
        .musikschule-planung-axis, .musikschule-planung-day { position: relative; height: var(--height); }
        .musikschule-planung-day { border-left: 1px solid var(--gray-200);
            background-image: repeating-linear-gradient(to bottom, transparent 0, transparent calc(var(--hour) - 1px), var(--gray-100) calc(var(--hour) - 1px), var(--gray-100) var(--hour)); }
        .dark .musikschule-planung-day { border-color: var(--gray-700);
            background-image: repeating-linear-gradient(to bottom, transparent 0, transparent calc(var(--hour) - 1px), var(--gray-800) calc(var(--hour) - 1px), var(--gray-800) var(--hour)); }
        .musikschule-planung-hour { position: absolute; right: .4rem; transform: translateY(-.55em); font-size: .7rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .musikschule-planung-block { position: absolute; box-sizing: border-box; padding: .2rem .35rem; overflow: hidden; border-radius: .4rem; font-size: .75rem; line-height: 1.2;
            background: color-mix(in srgb, var(--c) 16%, transparent); border-left: 4px solid var(--c); color: inherit; text-decoration: none; }
        .musikschule-planung-block:hover { background: color-mix(in srgb, var(--c) 28%, transparent); }
        .dark .musikschule-planung-block:hover { background: color-mix(in srgb, var(--c) 42%, transparent); }
        .dark .musikschule-planung-block { background: color-mix(in srgb, var(--c) 30%, transparent); }
        .musikschule-planung-block strong { display: block; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .musikschule-planung-block span { display: block; color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dark .musikschule-planung-block span { color: var(--gray-300); }
        .musikschule-planung-conflict { outline: 2px solid var(--danger-600); outline-offset: -2px; }
        .musikschule-planung-legend { display: flex; flex-wrap: wrap; gap: .4rem 1rem; font-size: .8rem; }
        .musikschule-planung-legend i { display: inline-block; width: .8rem; height: .8rem; border-radius: .2rem; margin-right: .3rem; vertical-align: -.1rem; background: var(--c); }
        .musikschule-planung-list { margin: 0; padding-left: 1.1rem; font-size: .875rem; }
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            .fi-sidebar, .fi-topbar, .fi-header, .musikschule-planung-form, .musikschule-planung-noprint { display: none !important; }
            .fi-main { padding: 0 !important; max-width: none !important; }
            .musikschule-planung-grid { min-width: 0; }
            .musikschule-planung-block, .musikschule-planung-legend i { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>

    @if ($this->ready())
        @php($plan = $this->timetable())
        @php($height = ($plan['to'] - $plan['from']) * $scale)

        <div class="musikschule-planung-head">
            <div>
                <div class="musikschule-planung-caption">{{ $this->caption() }}</div>
                @if ($plan['conflicts'] > 0)
                    <div class="musikschule-planung-warn">{{ __('musikschule-planung::planung.timetable.conflicts', ['count' => $plan['conflicts']]) }}</div>
                @elseif (collect($plan['days'])->flatten(1)->isEmpty() && $plan['unscheduled'] === [])
                    <div class="musikschule-planung-hint">{{ __('musikschule-planung::planung.timetable.empty') }}</div>
                @endif
            </div>
            <x-filament::button class="musikschule-planung-noprint" color="gray" icon="heroicon-o-printer" onclick="window.print()">
                {{ __('musikschule-planung::planung.timetable.print') }}
            </x-filament::button>
        </div>

        <div class="musikschule-planung-scroll">
            <div class="musikschule-planung-grid" style="--days: {{ count($plan['days']) }}; --height: {{ $height }}px; --hour: {{ 60 * $scale }}px;">
                <div class="musikschule-planung-corner"></div>
                @foreach (array_keys($plan['days']) as $day)
                    <div class="musikschule-planung-dayhead">{{ __('musikschule-planung::planung.days.'.$day) }}</div>
                @endforeach

                <div class="musikschule-planung-axis">
                    @foreach ($plan['hours'] as $hour)
                        @if ($hour * 60 > $plan['from'])
                            <span class="musikschule-planung-hour" style="top: {{ ($hour * 60 - $plan['from']) * $scale }}px">{{ sprintf('%02d:00', $hour) }}</span>
                        @endif
                    @endforeach
                </div>

                @foreach ($plan['days'] as $day => $blocks)
                    <div class="musikschule-planung-day" wire:key="musikschule-planung-{{ $day }}">
                        @foreach ($blocks as $b)
                            <a
                                href="{{ $b['url'] }}"
                                class="musikschule-planung-block @if ($b['conflict']) musikschule-planung-conflict @endif"
                                style="--c: {{ $b['color'] }}; top: {{ ($b['start'] - $plan['from']) * $scale }}px; height: {{ max(($b['end'] - $b['start']) * $scale - 2, 18) }}px;
                                    left: calc({{ 100 * $b['lane'] / $b['lanes'] }}% + 2px); width: calc({{ 100 / $b['lanes'] }}% - 4px);"
                                title="{{ $b['name'] }} · {{ $b['time'] }} · {{ $b['subject'] }}&#10;{{ $b['tooltip'] }}"
                                data-course="{{ $b['id'] }}"
                            >
                                <strong>{{ $b['name'] }}</strong>
                                <span>{{ $b['time'] }}</span>
                                @if ($b['detail'] !== '')
                                    <span>{{ $b['detail'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        @if ($plan['legend'] !== [])
            <div class="musikschule-planung-legend">
                @foreach ($plan['legend'] as $subject => $color)
                    <span><i style="--c: {{ $color }}"></i>{{ $subject }}</span>
                @endforeach
            </div>
        @endif

        @if ($plan['unscheduled'] !== [])
            <x-filament::section :heading="__('musikschule-planung::planung.timetable.unscheduled')" compact>
                <ul class="musikschule-planung-list">
                    @foreach ($plan['unscheduled'] as $b)
                        <li><a href="{{ $b['url'] }}">{{ $b['name'] }}</a>@if ($b['detail'] !== '') – {{ $b['detail'] }}@endif</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
