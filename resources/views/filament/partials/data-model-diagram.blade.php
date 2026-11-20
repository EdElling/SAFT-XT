{{-- Mermaid-ER-Diagramm mit „Felder anzeigen“ (Datenmodell einer Menügruppe oder App, Vorschau beim Installieren).
     Erwartet: compact, expanded, script, storageKey, hasOutside, hasExternal, hasLinks. Hell-/Dunkel-Modus: Filament
     setzt die Klasse „dark“ am html-Element, der Observer zeichnet neu. --}}
<div
    wire:ignore
    class="saft-data-model"
    style="display: flex; flex-direction: column; gap: 1rem;"
    x-data="{
        fields: false,
        compact: @js($compact),
        expanded: @js($expanded),
        script: @js($script),
        key: @js($storageKey),
        counter: 0,
        error: null,
        observer: null,
        async init() {
            try { this.fields = window.localStorage.getItem(this.key) === '1' } catch (e) {}
            try {
                await this.load()
            } catch (e) {
                this.error = @js(__('data_model.load_failed'));
                return;
            }
            this.$watch('fields', (value) => {
                try { window.localStorage.setItem(this.key, value ? '1' : '0') } catch (e) {}
                this.draw()
            })
            this.observer = new MutationObserver(() => this.draw())
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
            await this.draw()
        },
        destroy() {
            this.observer?.disconnect()
        },
        load() {
            if (window.mermaid) return Promise.resolve()
            window.saftMermaidLoading ??= new Promise((resolve, reject) => {
                const tag = document.createElement('script')
                tag.src = this.script
                tag.onload = resolve
                tag.onerror = () => { window.saftMermaidLoading = null; reject() }
                document.head.appendChild(tag)
            })
            return window.saftMermaidLoading
        },
        async draw() {
            const dark = document.documentElement.classList.contains('dark')
            window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: dark ? 'dark' : 'default', er: { useMaxWidth: false } })
            try {
                const { svg } = await window.mermaid.render('saft-data-model-' + (++this.counter), this.fields ? this.expanded : this.compact)
                this.$refs.canvas.innerHTML = svg
                this.$refs.canvas.dataset.fields = this.fields ? '1' : '0'
                this.error = null
            } catch (e) {
                this.error = @js(__('data_model.render_failed')) + ' ' + String(e?.message ?? e);
            }
        },
    }"
>
    <label style="display: inline-flex; align-items: center; gap: .5rem; font-size: .875rem;">
        <x-filament::input.checkbox x-model="fields" data-data-model-toggle />
        {{ __('data_model.show_fields') }}
    </label>

    <template x-if="error">
        <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle">
            <x-slot name="description"><span x-text="error"></span></x-slot>
        </x-filament::callout>
    </template>

    <div
        x-ref="canvas"
        data-data-model-canvas
        style="overflow: auto; max-height: 75vh; padding: 1rem; border: 1px solid var(--gray-200); border-radius: .75rem;"
        class="dark:border-white/10"
    ></div>

    <ul style="font-size: .8125rem; color: var(--gray-500); display: flex; flex-wrap: wrap; gap: .25rem 1.5rem; list-style: none; padding: 0; margin: 0;">
        <li>{{ __('data_model.legend.solid') }}</li>
        <li>{{ __('data_model.legend.dashed') }}</li>
        @if ($hasLinks ?? false)
            <li>{{ __('data_model.legend.link') }}</li>
        @endif
        <li>{{ __('data_model.legend.cardinality') }}</li>
        @if ($hasOutside)
            <li>{{ __('data_model.legend.outside') }}</li>
        @endif
        @if ($hasExternal)
            <li>{{ __('data_model.legend.external') }}</li>
        @endif
        <li x-show="fields">{{ __('data_model.legend.fields') }}</li>
    </ul>
</div>
