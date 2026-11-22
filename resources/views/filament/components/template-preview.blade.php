{{--
    Live-Vorschau einer Dokumentvorlage (PreviewsDocumentTemplate): Die Seite schickt das PDF als Ereignis
    „document-template-preview“ (Base64) samt Meldung (Dateiname oder Fehler); hier wird es als Blob angezeigt.
--}}
<div
    x-data="{
        url: null,
        message: '',
        failed: false,
        loading: true,
        mail: null,
        show(event) {
            const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail
            this.loading = false
            this.message = detail.message ?? ''
            this.failed = ! detail.pdf
            this.mail = detail.mail ?? null
            if (! detail.pdf) return
            if (this.url) URL.revokeObjectURL(this.url)
            const bytes = Uint8Array.from(atob(detail.pdf), (c) => c.charCodeAt(0))
            this.url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }))
        },
    }"
    x-init="$wire.refreshPreview()"
    x-on:document-template-preview.window="show($event)"
    style="display: flex; flex-direction: column; gap: .5rem"
>
    <p x-show="loading" style="font-size: .85rem; color: var(--gray-500)">{{ __('documents.templates.preview.loading') }}</p>
    <p x-show="message !== ''" x-text="message"
       :style="failed ? 'white-space: pre-line; font-size: .85rem; color: var(--danger-600)' : 'font-size: .8rem; color: var(--gray-500)'"></p>
    <template x-if="mail">
        <div>
            <p style="font-size: .8rem; font-weight: 600; margin-bottom: .25rem">{{ __('documents.templates.preview.mail') }}</p>
            <pre x-text="mail" data-template-mail-preview
                style="white-space: pre-wrap; font-size: .8rem; padding: .5rem .65rem; border: 1px solid var(--gray-200); border-radius: .5rem; margin: 0"></pre>
        </div>
    </template>
    <template x-if="url">
        <iframe :src="url" title="{{ __('documents.templates.sections.preview') }}"
            style="width: 100%; height: 75vh; border: 1px solid var(--gray-200); border-radius: .5rem; background: #fff"></iframe>
    </template>
</div>
