{{--
    Bausteine für ein Formelfeld (Textarea mit data-formula-input): ein Klick fügt den Text an der Schreibmarke
    des nächstgelegenen Formelfelds ein – im Repeater also in das Feld desselben Eintrags.

    $groups:  list<array{label: string, items: list<array{label: string, insert: string, title?: string}>}>
    $buttons: list<array{label: string, insert: string, title?: string}>  Rechenzeichen und Funktionen (Tooltip: Erklärung)
    $buttonsLabel: optionale Überschrift über den Knöpfen
    $empty:   Text, wenn keine Felder da sind
--}}
<div x-data="{
    insert(text) {
        let scope = $el
        while (scope && ! scope.querySelector('textarea[data-formula-input]')) scope = scope.parentElement
        const input = scope?.querySelector('textarea[data-formula-input]')
        if (! input) return
        const start = input.selectionStart ?? input.value.length
        const end = input.selectionEnd ?? start
        input.value = input.value.slice(0, start) + text + input.value.slice(end)
        input.dispatchEvent(new Event('input', { bubbles: true }))
        input.focus()
        input.setSelectionRange(start + text.length, start + text.length)
    },
}" style="display: flex; flex-direction: column; gap: .5rem">
    @php($hasItems = collect($groups)->contains(fn (array $g): bool => $g['items'] !== []))
    @if (! $hasItems)
        <p style="font-size: .8rem; color: var(--gray-500)">{{ $empty }}</p>
    @endif
    @foreach ($groups as $group)
        @continue($group['items'] === [])
        <div>
            <p style="font-size: .875rem; font-weight: 500; margin-bottom: .3rem">{{ $group['label'] }}</p>
            <div style="display: flex; flex-wrap: wrap; gap: .3rem; max-height: 9rem; overflow-y: auto">
                @foreach ($group['items'] as $item)
                    <button type="button" x-on:click="insert(@js($item['insert']))" title="{{ $item['title'] ?? $item['insert'] }}"
                        style="font-size: .8rem; padding: .15rem .5rem; border-radius: 9999px; border: 1px solid var(--gray-300); background: var(--gray-50)"
                        class="dark:!bg-white/5 dark:!border-white/10">{{ $item['label'] }}</button>
                @endforeach
            </div>
        </div>
    @endforeach
    @if (filled($buttonsLabel ?? null) && $buttons !== [])
        <p style="font-size: .875rem; font-weight: 500; margin-bottom: -.2rem">{{ $buttonsLabel }}</p>
    @endif
    <p style="font-size: .75rem; display: flex; flex-wrap: wrap; gap: .2rem">
        @foreach ($buttons as $button)
            <button type="button" x-on:click="insert(@js($button['insert']))" title="{{ $button['title'] ?? trim($button['insert']) }}"
                style="font-family: ui-monospace, monospace; padding: 0 .4rem; border: 1px solid var(--gray-300); border-radius: .25rem"
                class="dark:!border-white/10">{{ $button['label'] }}</button>
        @endforeach
    </p>
</div>
