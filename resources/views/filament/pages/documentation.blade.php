{{-- Online-Hilfe: Reiter je Handbuch (+ „Über SAFT“), Inhaltsverzeichnis links, Suche über alle sichtbaren Handbücher. --}}
@php
    $tabs = [...array_keys($docs), ...($assistant ? ['assistant'] : []), 'about'];
@endphp
<x-filament-panels::page>
    <style>
        .saft-docs { display: grid; grid-template-columns: minmax(12rem, 17rem) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
        @media (max-width: 1024px) { .saft-docs { grid-template-columns: minmax(0, 1fr); } .saft-docs .toc { display: none; } }
        .saft-docs .toc { position: sticky; top: 5rem; max-height: calc(100vh - 6rem); overflow: auto; font-size: .85rem; }
        .saft-docs .toc a { display: block; padding: .15rem .25rem; border-radius: .25rem; color: var(--gray-600); }
        .dark .saft-docs .toc a { color: var(--gray-300); }
        .saft-docs .toc a:hover { background: var(--gray-100); }
        .dark .saft-docs .toc a:hover { background: var(--gray-800); }
        .saft-docs .toc a.l3 { padding-inline-start: 1rem; font-size: .8rem; }
        .saft-docs .tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
        .saft-docs .search { width: 100%; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .45rem .7rem; background: transparent; margin-bottom: .75rem; }
        .saft-docs .results { border: 1px solid var(--gray-200); border-radius: .5rem; padding: .5rem; margin-bottom: 1rem; }
        .dark .saft-docs .results { border-color: var(--gray-700); }
        .saft-docs .results button { display: block; width: 100%; text-align: start; padding: .35rem .5rem; border-radius: .35rem; }
        .saft-docs .results button:hover, .saft-docs .results button:focus-visible { background: var(--gray-100); outline: none; }
        .dark .saft-docs .results button:hover, .dark .saft-docs .results button:focus-visible { background: var(--gray-800); }
        .saft-docs .results small { display: block; color: var(--gray-500); }
        /* Lesetext */
        .saft-doc { max-width: 80rem; line-height: 1.6; overflow-wrap: anywhere; }
        .saft-doc h2 { font-size: 1.35rem; font-weight: 700; margin: 2rem 0 .75rem; scroll-margin-top: 5rem; }
        .saft-doc h3 { font-size: 1.1rem; font-weight: 650; margin: 1.5rem 0 .5rem; scroll-margin-top: 5rem; }
        .saft-doc h4 { font-weight: 650; margin: 1rem 0 .4rem; scroll-margin-top: 5rem; }
        .saft-doc p, .saft-doc ul, .saft-doc ol, .saft-doc blockquote, .saft-doc table, .saft-doc pre { margin: 0 0 .85rem; }
        .saft-doc ul { list-style: disc; padding-inline-start: 1.4rem; }
        .saft-doc ol { list-style: decimal; padding-inline-start: 1.4rem; }
        .saft-doc a { color: var(--primary-600); text-decoration: underline; }
        .dark .saft-doc a { color: var(--primary-400); }
        .saft-doc code { font-size: .85em; background: var(--gray-100); padding: .05rem .3rem; border-radius: .25rem; }
        .dark .saft-doc code { background: var(--gray-800); }
        .saft-doc pre { background: var(--gray-100); padding: .75rem; border-radius: .5rem; overflow: auto; }
        .dark .saft-doc pre { background: var(--gray-800); }
        .saft-doc pre code { background: none; padding: 0; }
        .saft-doc blockquote { border-inline-start: 3px solid var(--primary-400); padding: .25rem .9rem; color: var(--gray-600); }
        .dark .saft-doc blockquote { color: var(--gray-300); }
        .saft-doc table { display: block; overflow-x: auto; border-collapse: collapse; font-size: .9rem; }
        .saft-doc th, .saft-doc td { border: 1px solid var(--gray-200); padding: .35rem .6rem; text-align: start; vertical-align: top; }
        .dark .saft-doc th, .dark .saft-doc td { border-color: var(--gray-700); }
        .saft-doc th { background: var(--gray-50); font-weight: 600; }
        .dark .saft-doc th { background: var(--gray-800); }
        .saft-doc img { max-width: 100%; height: auto; border: 1px solid var(--gray-200); border-radius: .5rem; margin: .5rem 0 1rem; }
        .saft-doc hr { margin: 1.5rem 0; border-color: var(--gray-200); }
        .saft-doc mark { background: color-mix(in oklab, var(--warning-400) 45%, transparent); color: inherit; border-radius: .15rem; }
    </style>

    <div class="saft-docs" data-documentation
         x-data="{
             tab: @js($tabs[0]),
             q: '',
             results: [],
             open(id) {
                 const target = document.getElementById(id);
                 if (! target) return;
                 this.tab = target.closest('[data-doc]').dataset.doc;
                 this.$nextTick(() => { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); history.replaceState(null, '', '#' + id); });
             },
             search() {
                 const words = this.q.trim().toLowerCase().split(/\s+/).filter((w) => w.length > 1);
                 if (words.length === 0) { this.results = []; return; }
                 this.results = [...document.querySelectorAll('[data-section]')]
                     .filter((s) => words.every((w) => s.dataset.text.includes(w)))
                     .slice(0, 30)
                     .map((s) => {
                         const i = s.dataset.text.indexOf(words[0]);
                         return { id: s.dataset.section, title: s.dataset.title, doc: s.closest('[data-doc]').dataset.label, snippet: '… ' + s.dataset.text.slice(Math.max(0, i - 50), i + 90) + ' …' };
                     });
             },
         }"
         x-init="if (location.hash.length > 1) { $nextTick(() => open(location.hash.slice(1))) }"
         x-on:click="const a = $event.target.closest('[data-doc-link]'); if (a) { $event.preventDefault(); open(a.getAttribute('href').slice(1)) }">
        <nav class="toc" aria-label="{{ __('documentation.contents') }}">
            @foreach ($docs as $key => $doc)
                <div x-show="tab === @js($key)">
                    @foreach ($doc['sections'] as $section)
                        <a href="#{{ $section['id'] }}" data-doc-link @class(['l3' => $section['level'] === 3])>{{ $section['title'] }}</a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div style="min-width: 0">
            <input type="search" class="search" x-model.debounce.200ms="q" x-on:input.debounce.200ms="search()" placeholder="{{ __('documentation.search') }}" aria-label="{{ __('documentation.search') }}" data-doc-search>

            <div class="results" x-show="q.trim().length > 1" x-cloak data-doc-results>
                <p class="text-sm text-gray-500" x-show="results.length === 0">{{ __('documentation.no_results') }}</p>
                <template x-for="r in results" :key="r.id">
                    <button type="button" x-on:click="q = ''; results = []; open(r.id)" data-doc-result>
                        <span class="font-medium" x-text="r.title"></span>
                        <small x-text="r.doc + ' · ' + r.snippet"></small>
                    </button>
                </template>
            </div>

            <x-filament::tabs class="tabs" :contained="false">
                @foreach ($tabs as $key)
                    <x-filament::tabs.item :alpine-active="'tab === ' . \Illuminate\Support\Js::from($key)" x-on:click="tab = {{ \Illuminate\Support\Js::from($key) }}" data-doc-tab="{{ $key }}">
                        {{ match ($key) { 'about' => __('documentation.about'), 'assistant' => __('help.tab'), default => $docs[$key]['label'] } }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>

            @foreach ($docs as $key => $doc)
                <article class="saft-doc" data-doc="{{ $key }}" data-label="{{ $doc['label'] }}" x-show="tab === @js($key)" @if (! $loop->first) x-cloak @endif>
                    @if ($doc['language'] === 'en' && app()->getLocale() !== 'en')
                        <p class="text-sm text-gray-500" style="margin-bottom: 1rem" data-language-note>{{ __('documentation.language_note') }}</p>
                    @endif
                    @foreach ($doc['sections'] as $section)
                        <section data-section="{{ $section['id'] }}" data-title="{{ $section['title'] }}" data-text="{{ $section['text'] }}">
                            {!! $section['html'] !!}
                        </section>
                    @endforeach
                </article>
            @endforeach

            @if ($assistant)
                {{-- Hilfe-Assistent: Verweise in Antworten springen über data-doc-link in den Abschnitt. --}}
                <article data-doc="assistant" data-label="{{ __('help.tab') }}" x-show="tab === 'assistant'" x-cloak>
                    @livewire(\App\Livewire\HelpAssistantChat::class, ['onDocumentationPage' => true], key('help-assistant-docs'))
                </article>
            @endif

            <article class="saft-doc" data-doc="about" data-label="{{ __('documentation.about') }}" x-show="tab === 'about'" x-cloak>
                <h2>{{ __('documentation.about') }}</h2>
                <p><strong>SAFT</strong> · {{ __('documentation.version') }} <strong data-saft-version>{{ $version }}</strong>
                    · {{ __('documentation.plugin_api') }} <strong data-saft-api-version>{{ \App\Plugins\Api\ApiManifest::current() }}</strong></p>
                <h3>{{ __('documentation.components') }}</h3>
                <ul>
                    @foreach ($versions as $name => $value)
                        <li>{{ $name }} {{ $value }}</li>
                    @endforeach
                </ul>
                <h3>{{ __('documentation.license') }}</h3>
                {{-- Der Verweis im Kopf der LICENSE auf THIRD_PARTY_NOTICES.md führt zur Liste weiter unten (aufklappen und hinspringen). --}}
                <pre style="white-space: pre-wrap; font-family: inherit; font-size: .85rem" data-saft-license
                     x-on:click="if ($event.target.closest('[data-notices-link]')) { $event.preventDefault(); const d = document.getElementById('third-party-components'); d.open = true; d.scrollIntoView({ behavior: 'smooth', block: 'start' }); }">{!! $notices === null ? e($license) : str_replace('THIRD_PARTY_NOTICES.md', '<a href="#third-party-components" data-notices-link>THIRD_PARTY_NOTICES.md</a>', e($license)) !!}</pre>
                @if ($notices !== null)
                    <h3>{{ __('documentation.third_party') }}</h3>
                    <p>{{ __('documentation.third_party_help') }}</p>
                    <details id="third-party-components" data-saft-notices style="scroll-margin-top: 5rem">
                        <summary style="cursor: pointer; font-weight: 600">{{ __('documentation.third_party_show') }}</summary>
                        <div style="margin-top: .75rem">{!! $notices !!}</div>
                    </details>
                @endif
            </article>
        </div>
    </div>
</x-filament-panels::page>
