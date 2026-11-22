{{--
    Feldauswahl für Dokumentvorlagen: Ein Klick fügt den Platzhalter an der Schreibmarke ein – im zuletzt benutzten
    Feld (Empfänger oder Inhalt), sonst im Inhalt. Der Inhalt ist ein CodeMirror-Editor (Filament CodeEditor); dessen
    Instanz liegt in den Alpine-Daten des Editors.

    $groups: list<array{label: string, items: list<array{label: string, insert: string, title?: string}>}>
--}}
<div x-data="{
    target: null,
    init() {
        const scope = $el.closest('form') ?? document
        scope.addEventListener('focusin', (event) => {
            if (event.target.matches('textarea[data-template-input]') || event.target.closest('.cm-editor')) this.target = event.target
        })
    },
    insert(text) {
        const scope = $el.closest('form') ?? document
        let target = this.target && document.contains(this.target) ? this.target : scope.querySelector('.cm-content')
        if (! target) return
        if (target.matches('textarea')) {
            const start = target.selectionStart ?? target.value.length
            const end = target.selectionEnd ?? start
            target.value = target.value.slice(0, start) + text + target.value.slice(end)
            target.dispatchEvent(new Event('input', { bubbles: true }))
            target.focus()
            target.setSelectionRange(start + text.length, start + text.length)
            return
        }
        const host = target.closest('.cm-editor')?.parentElement?.closest('[x-data]')
        const editor = host ? Alpine.$data(host).editor : null
        if (! editor) return
        const range = editor.state.selection.main
        editor.dispatch({ changes: { from: range.from, to: range.to, insert: text }, selection: { anchor: range.from + text.length } })
        editor.focus()
    },
}" style="display: flex; flex-direction: column; gap: .6rem">
    <p style="font-size: .875rem; font-weight: 600">{{ __('documents.templates.picker.title') }}</p>
    <p style="font-size: .75rem; color: var(--gray-500); margin-top: -.4rem">{{ __('documents.templates.picker.help') }}</p>
    @if ($groups === [])
        <p style="font-size: .8rem; color: var(--gray-500)">{{ __('documents.templates.picker.empty') }}</p>
    @endif
    @foreach ($groups as $group)
        @continue($group['items'] === [])
        <details @if ($loop->first || $loop->last) open @endif>
            <summary style="font-size: .85rem; font-weight: 500; cursor: pointer">{{ $group['label'] }}</summary>
            <div style="display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .3rem">
                @foreach ($group['items'] as $item)
                    <button type="button" x-on:mousedown.prevent x-on:click="insert(@js($item['insert']))" title="{{ $item['title'] ?? $item['insert'] }}"
                        style="font-size: .8rem; padding: .15rem .5rem; border-radius: 9999px; border: 1px solid var(--gray-300); background: var(--gray-50)"
                        class="dark:!bg-white/5 dark:!border-white/10">{{ $item['label'] }}</button>
                @endforeach
            </div>
        </details>
    @endforeach
</div>
