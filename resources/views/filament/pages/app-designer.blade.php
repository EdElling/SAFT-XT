{{-- KI-App-Designer (B-107, Etappe 7a): Entwürfe | Gespräch | Vorschau. --}}
<x-filament-panels::page>
    <style>
        .saft-designer { display: grid; grid-template-columns: minmax(10rem, 13rem) minmax(0, 1fr) minmax(0, 1.25fr); gap: 1.25rem; align-items: start; }
        @media (max-width: 1280px) { .saft-designer { grid-template-columns: minmax(10rem, 13rem) minmax(0, 1fr); } .saft-designer .preview { grid-column: 1 / -1; } }
        @media (max-width: 768px) { .saft-designer { grid-template-columns: minmax(0, 1fr); } }
        .saft-designer .drafts { font-size: .85rem; display: flex; flex-direction: column; gap: .15rem; }
        .saft-designer .drafts .item { display: flex; align-items: center; gap: .25rem; border-radius: .4rem; }
        .saft-designer .drafts .item button.open { flex: 1; text-align: start; padding: .3rem .45rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .saft-designer .drafts .item:hover, .saft-designer .drafts .item.active { background: var(--gray-100); }
        .dark .saft-designer .drafts .item:hover, .dark .saft-designer .drafts .item.active { background: var(--gray-800); }
        .saft-designer .drafts small { color: var(--gray-500); padding-inline-start: .45rem; }
        .saft-designer .chat { display: flex; flex-direction: column; gap: .8rem; min-width: 0; }
        .saft-designer .msg { line-height: 1.5; overflow-wrap: anywhere; white-space: pre-wrap; }
        .saft-designer .msg.user { align-self: flex-end; max-width: 90%; background: color-mix(in oklab, var(--primary-500) 12%, transparent); padding: .5rem .8rem; border-radius: .75rem; }
        .saft-designer .msg.assistant { color: var(--gray-700); }
        .dark .saft-designer .msg.assistant { color: var(--gray-300); }
        .saft-designer textarea { width: 100%; min-height: 7rem; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .5rem .7rem; background: transparent; resize: vertical; }
        .dark .saft-designer textarea { border-color: var(--gray-600); }
        .saft-designer .note { font-size: .8rem; color: var(--gray-500); }
        .saft-designer .preview { min-width: 0; font-size: .875rem; display: flex; flex-direction: column; gap: .75rem; }
        .saft-designer .preview table { width: 100%; border-collapse: collapse; font-size: .8rem; }
        .saft-designer .preview td { padding: .15rem .4rem; border-bottom: 1px solid var(--gray-200); vertical-align: top; }
        .dark .saft-designer .preview td { border-color: var(--gray-700); }
        .saft-designer .preview td.detail { color: var(--gray-500); font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
    </style>

    <div class="saft-designer" data-app-designer>
            <nav class="drafts" aria-label="{{ __('app_designer.drafts') }}" data-designer-drafts>
                <strong style="padding: .2rem .45rem">{{ __('app_designer.drafts') }}</strong>
                @forelse ($drafts as $item)
                    <div class="item @if ($current?->id === $item->id) active @endif" wire:key="draft-{{ $item->id }}">
                        <button type="button" class="open" wire:click="openDraft({{ $item->id }})" title="{{ $item->title }}" data-designer-draft="{{ $item->id }}">
                            {{ $item->title }}
                            @if ($item->status === \App\Models\AppDraft::CREATED)<small>· {{ __('app_designer.status_created') }}</small>@endif
                        </button>
                        <x-filament::icon-button icon="heroicon-m-trash" size="xs" color="gray" :label="__('app_designer.delete')" wire:click="deleteDraft({{ $item->id }})" wire:confirm="{{ __('app_designer.delete_confirm') }}" />
                    </div>
                @empty
                    <p class="note" style="padding: .2rem .45rem">{{ __('app_designer.no_drafts') }}</p>
                @endforelse
            </nav>

            <section class="chat" data-designer-chat>
                @if ($messages === [])
                    <p class="note" data-designer-intro>{{ __('app_designer.intro') }}</p>
                @endif

                @foreach ($messages as $message)
                    <div class="msg {{ $message['role'] === 'user' ? 'user' : 'assistant' }}" data-designer-message="{{ $message['role'] }}">{{ $message['text'] }}</div>
                @endforeach

                @if ($current?->status === \App\Models\AppDraft::CREATED)
                    <p class="note" data-designer-created>{{ __('app_designer.created_note', ['app' => (string) $current->app?->name]) }}</p>
                @elseif (! $available)
                    {{-- Ohne KI: Entwürfe und Vorschau bleiben, nur keine neuen Runden. --}}
                    <p class="text-sm text-warning-700 dark:text-warning-400" data-designer-unavailable>{{ __('app_designer.unavailable') }}</p>
                @else
                    <form wire:submit="send" style="display: flex; flex-direction: column; gap: .5rem">
                        <textarea wire:model="prompt" placeholder="{{ $messages === [] ? __('app_designer.placeholder_new') : __('app_designer.placeholder_change') }}" aria-label="{{ __('app_designer.prompt') }}" data-designer-prompt></textarea>
                        @if ($notice)
                            <p class="text-sm text-danger-600 dark:text-danger-400" data-designer-notice>{{ $notice }}</p>
                        @endif
                        <div style="display: flex; align-items: center; gap: .75rem">
                            <x-filament::button type="submit" icon="heroicon-m-sparkles" wire:loading.attr="disabled" wire:target="send" data-designer-send>
                                {{ $messages === [] ? __('app_designer.send_new') : __('app_designer.send_change') }}
                            </x-filament::button>
                            <span class="note" wire:loading wire:target="send">{{ __('app_designer.working') }}</span>
                        </div>
                    </form>
                @endif
            </section>

            <section class="preview" data-designer-preview>
                @if ($definition === null)
                    <p class="note">{{ __('app_designer.no_preview') }}</p>
                @else
                    <div>
                        <p class="text-base font-semibold" data-designer-app-name>
                            {{ ($definition['app']['name'] ?? [])[app()->getLocale()] ?? ($definition['app']['name'] ?? [])[config('locales.default')] ?? '' }}
                            <span class="font-normal text-gray-500">({{ __('apps.prefix') }}: {{ $definition['app']['prefix'] }})</span>
                        </p>
                        @if (filled(($definition['app']['description'] ?? [])[app()->getLocale()] ?? null))
                            <p class="note">{{ $definition['app']['description'][app()->getLocale()] }}</p>
                        @endif
                    </div>

                    @if ($analysis->blockers !== [])
                        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-designer-blockers>
                            <p class="font-semibold">{{ __('apps.install.blocked') }}</p>
                            <ul style="list-style: disc; padding-inline-start: 1.5rem;">@foreach ($analysis->blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <p class="note" data-designer-counts>{{ __('app_designer.summary', ['tables' => count($tables), 'fields' => array_sum(array_map(fn (array $t): int => count($t['fields']), $tables)), 'menus' => count($definition['menus'] ?? [])]) }}</p>

                    @if ($analysis->warnings !== [])
                        <div class="rounded-lg bg-warning-50 p-3 text-warning-800 dark:bg-warning-500/10 dark:text-warning-400" data-designer-warnings>
                            <ul style="list-style: disc; padding-inline-start: 1.5rem;">@foreach ($analysis->warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                        </div>
                    @endif

                    @if ($diagram !== null)
                        @include('filament.partials.data-model-diagram', $diagram)
                    @endif

                    @foreach ($tables as $table)
                        <details @if ($loop->first) open @endif data-designer-table="{{ $table['name'] }}">
                            <summary style="cursor: pointer"><strong>{{ $table['label'] }}</strong> <span class="note">({{ $table['name'] }}, {{ trans_choice('app_designer.fields', count($table['fields']), ['count' => count($table['fields'])]) }})</span></summary>
                            <table>
                                @foreach ($table['fields'] as $field)
                                    <tr>
                                        <td>{{ $field['label'] }} <span class="note">({{ $field['name'] }})</span></td>
                                        <td>{{ $field['type'] }}</td>
                                        <td class="detail">{{ $field['detail'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </details>
                    @endforeach
                @endif
            </section>
    </div>
</x-filament-panels::page>
