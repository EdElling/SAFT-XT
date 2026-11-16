{{--
    Auswahldialog für Icons (App\Filament\Forms\Components\IconPicker): Reiter je Stil, Suche über
    alle Reiter, Klick übernimmt den Namen und schließt den Dialog.
--}}
@php
    $statePath = $getStatePath();
    // Gesucht wird im eigentlichen Namen ohne Präfix (heroicon-o-users → users).
    $short = static fn (string $icon): string => (string) preg_replace('/^heroicon-[oscm]-/', '', $icon);
    $names = collect($groups)->mapWithKeys(fn (array $group): array => [$group['key'] => array_map($short, $group['icons'])])->all();
    $initialTab = $currentGroup ?? ($groups[0]['key'] ?? null);
@endphp

<div
    class="saft-icon-picker"
    x-data="{
        tab: @js($initialTab),
        q: '',
        names: @js($names),
        term() {
            return this.q.trim().toLowerCase()
        },
        matches(name) {
            const term = this.term()

            return term === '' || name.includes(term)
        },
        count(key) {
            return this.names[key].filter((name) => this.matches(name)).length
        },
        pick(name) {
            $wire.set(@js($statePath), name, false)
            $wire.callMountedAction()
        },
    }"
>
    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
        <x-filament::input
            type="search"
            x-model.debounce.150ms="q"
            :placeholder="__('common.icon_picker.search')"
            autocomplete="off"
            x-init="$nextTick(() => $el.focus())"
        />
    </x-filament::input.wrapper>

    <x-filament::tabs class="saft-icon-picker-tabs">
        @foreach ($groups as $group)
            <x-filament::tabs.item
                :alpine-active="'tab === ' . \Illuminate\Support\Js::from($group['key'])"
                x-on:click="tab = {{ \Illuminate\Support\Js::from($group['key']) }}"
                :alpine-deferred-badge-data="'{ badge: count(' . \Illuminate\Support\Js::from($group['key']) . ') }'"
                alpine-deferred-badge-loading="false"
                data-group="{{ $group['key'] }}"
            >
                {{ $group['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @foreach ($groups as $group)
        <div
            class="saft-icon-picker-grid"
            x-show="tab === {{ \Illuminate\Support\Js::from($group['key']) }}"
            @if ($group['key'] !== $initialTab) x-cloak @endif
        >
            @foreach ($group['icons'] as $icon)
                <button
                    type="button"
                    @class(['saft-icon-picker-item', 'is-current' => $icon === $current])
                    x-show="matches(@js($short($icon)))"
                    x-on:click="pick(@js($icon))"
                    title="{{ $icon }}"
                    aria-label="{{ $icon }}"
                    data-icon="{{ $icon }}"
                    @if ($icon === $current) aria-current="true" @endif
                >
                    {{ svg($icon, '', ['aria-hidden' => 'true']) }}
                    <span>{{ $short($icon) }}</span>
                </button>
            @endforeach

            <p class="saft-icon-picker-empty" x-show="count({{ \Illuminate\Support\Js::from($group['key']) }}) === 0" x-cloak>
                {{ __('common.icon_picker.none') }}
            </p>
        </div>
    @endforeach

    <style>
        .saft-icon-picker { display: flex; flex-direction: column; gap: 1rem; }
        .saft-icon-picker-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr)); gap: .5rem;
            max-height: 60vh; overflow-y: auto; padding: .25rem;
        }
        .saft-icon-picker-item {
            display: flex; flex-direction: column; align-items: center; gap: .375rem;
            padding: .625rem .25rem; border-radius: .5rem; border: 1px solid transparent;
            color: var(--gray-700); font-size: .6875rem; line-height: 1.2; text-align: center; cursor: pointer;
        }
        .dark .saft-icon-picker-item { color: var(--gray-200); }
        .saft-icon-picker-item svg { width: 1.5rem; height: 1.5rem; flex: none; }
        .saft-icon-picker-item span { overflow-wrap: anywhere; }
        .saft-icon-picker-item:hover, .saft-icon-picker-item:focus-visible {
            background: var(--gray-100); border-color: var(--gray-300); outline: none;
        }
        .dark .saft-icon-picker-item:hover, .dark .saft-icon-picker-item:focus-visible {
            background: var(--gray-800); border-color: var(--gray-600);
        }
        .saft-icon-picker-item.is-current { border-color: var(--primary-600); color: var(--primary-700); font-weight: 600; }
        .dark .saft-icon-picker-item.is-current { border-color: var(--primary-400); color: var(--primary-300); }
        .saft-icon-picker-empty { grid-column: 1 / -1; color: var(--gray-500); padding: 1rem 0; }
    </style>
</div>
