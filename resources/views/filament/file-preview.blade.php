{{--
    Vorschau von Dateien aus Feldern vom Typ „Datei“ (B-021): Bilder und PDF in einem Overlay, mit Blättern durch alle
    Dateien des Felds. Die Links in Liste und Details (DynamicTableBuilder::fileSummary) rufen saftFilePreview() auf;
    ohne JavaScript öffnen sie die Datei wie gewohnt in einem neuen Tab.
--}}
<script>
    window.saftFilePreview = function (link, always) {
        const list = link.closest('[data-saft-files]');
        if (!list) return false;
        let files = [];
        try { files = JSON.parse(list.dataset.saftFiles || '[]'); } catch (e) { return false; }
        const index = parseInt(link.dataset.saftFile || '0', 10);
        if (!files[index] || (!always && !files[index].preview)) return false;
        window.dispatchEvent(new CustomEvent('saft-file-preview', { detail: { files: files, index: index } }));
        return true;
    };
</script>
<div
    x-data="{
        open: false,
        files: [],
        index: 0,
        get file() { return this.files[this.index] || null },
        show(detail) { this.files = detail.files; this.index = detail.index; this.open = true; this.$nextTick(() => this.$refs.close?.focus()) },
        step(delta) { if (this.files.length > 1) this.index = (this.index + delta + this.files.length) % this.files.length },
    }"
    x-on:saft-file-preview.window="show($event.detail)"
    x-on:keydown.escape.window="open = false"
    x-on:keydown.arrow-left.window="open && step(-1)"
    x-on:keydown.arrow-right.window="open && step(1)"
    x-show="open"
    x-cloak
    class="saft-file-preview"
    role="dialog"
    aria-modal="true"
    data-saft-file-preview
>
    <div class="saft-file-preview-backdrop" x-on:click="open = false"></div>
    <div class="saft-file-preview-window">
        <div class="saft-file-preview-header">
            <span class="saft-file-preview-name" x-text="file?.name"></span>
            <span class="saft-file-preview-count" x-show="files.length > 1" x-text="(index + 1) + ' / ' + files.length"></span>
            <a class="saft-file-preview-action" x-bind:href="file?.url" target="_blank" rel="noopener" title="{{ __('data.files.open_new_tab') }}">
                {{ svg('heroicon-o-arrow-top-right-on-square', 'saft-file-preview-icon') }}<span class="sr-only">{{ __('data.files.open_new_tab') }}</span>
            </a>
            <a class="saft-file-preview-action" x-bind:href="file ? file.url + '?download=1' : '#'" title="{{ __('data.files.download') }}" data-saft-file-download>
                {{ svg('heroicon-o-arrow-down-tray', 'saft-file-preview-icon') }}<span class="sr-only">{{ __('data.files.download') }}</span>
            </a>
            <button type="button" class="saft-file-preview-action" x-ref="close" x-on:click="open = false" title="{{ __('data.files.close') }}">
                {{ svg('heroicon-o-x-mark', 'saft-file-preview-icon') }}<span class="sr-only">{{ __('data.files.close') }}</span>
            </button>
        </div>
        <div class="saft-file-preview-body">
            <button type="button" class="saft-file-preview-nav saft-file-preview-prev" x-show="files.length > 1" x-on:click="step(-1)" title="{{ __('data.files.previous') }}">
                {{ svg('heroicon-o-chevron-left', 'saft-file-preview-icon') }}<span class="sr-only">{{ __('data.files.previous') }}</span>
            </button>
            <template x-if="open && file?.preview === 'image'">
                <img class="saft-file-preview-image" x-bind:src="file.url" x-bind:alt="file.name">
            </template>
            <template x-if="open && file?.preview === 'pdf'">
                <iframe class="saft-file-preview-pdf" x-bind:src="file.url" x-bind:title="file.name"></iframe>
            </template>
            <template x-if="open && file && !file.preview">
                <div class="saft-file-preview-none">
                    {{ svg('heroicon-o-document', 'saft-file-preview-doc') }}
                    <p>{{ __('data.files.no_preview') }}</p>
                    <a class="saft-file-preview-download" x-bind:href="file.url + '?download=1'">{{ __('data.files.download') }}</a>
                </div>
            </template>
            <button type="button" class="saft-file-preview-nav saft-file-preview-next" x-show="files.length > 1" x-on:click="step(1)" title="{{ __('data.files.next') }}">
                {{ svg('heroicon-o-chevron-right', 'saft-file-preview-icon') }}<span class="sr-only">{{ __('data.files.next') }}</span>
            </button>
        </div>
    </div>
</div>
