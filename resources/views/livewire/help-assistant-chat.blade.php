{{-- Hilfe-Assistent: Unterhaltungen links (im seitlichen Fenster eingeklappt), Verlauf und Eingabe rechts. --}}
<div class="saft-help @if ($compact) saft-help-compact @endif" data-help-assistant>
    <style>
        .saft-help { display: grid; grid-template-columns: minmax(11rem, 15rem) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
        .saft-help-compact { grid-template-columns: minmax(0, 1fr); }
        @media (max-width: 1024px) { .saft-help { grid-template-columns: minmax(0, 1fr); } }
        .saft-help-list { font-size: .85rem; display: flex; flex-direction: column; gap: .15rem; }
        .saft-help-list .item { display: flex; align-items: center; gap: .25rem; border-radius: .4rem; }
        .saft-help-list .item button.open { flex: 1; text-align: start; padding: .3rem .45rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .saft-help-list .item:hover, .saft-help-list .item.active { background: var(--gray-100); }
        .dark .saft-help-list .item:hover, .dark .saft-help-list .item.active { background: var(--gray-800); }
        .saft-help-list small { color: var(--gray-500); padding-inline-start: .45rem; }
        .saft-help-chat { display: flex; flex-direction: column; gap: .9rem; min-width: 0; }
        .saft-help-msg { line-height: 1.55; overflow-wrap: anywhere; }
        .saft-help-msg.user { align-self: flex-end; max-width: 85%; background: color-mix(in oklab, var(--primary-500) 12%, transparent); padding: .5rem .8rem; border-radius: .75rem; white-space: pre-wrap; }
        .saft-help-msg.assistant p, .saft-help-msg.assistant ul, .saft-help-msg.assistant ol { margin: 0 0 .6rem; }
        .saft-help-msg.assistant ul { list-style: disc; padding-inline-start: 1.3rem; }
        .saft-help-msg.assistant ol { list-style: decimal; padding-inline-start: 1.3rem; }
        .saft-help-msg.assistant a { color: var(--primary-600); text-decoration: underline; }
        .dark .saft-help-msg.assistant a { color: var(--primary-400); }
        .saft-help-msg.assistant code { font-size: .85em; background: var(--gray-100); padding: .05rem .3rem; border-radius: .25rem; }
        .dark .saft-help-msg.assistant code { background: var(--gray-800); }
        .saft-help-input textarea { width: 100%; min-height: 4.5rem; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .5rem .7rem; background: transparent; resize: vertical; }
        .dark .saft-help-input textarea { border-color: var(--gray-600); }
        .saft-help-note { font-size: .8rem; color: var(--gray-500); }
    </style>

    @if (! $available)
        <p class="saft-help-note" data-help-unavailable>{{ __('help.unavailable') }}</p>
    @else
        @if ($compact)
            <details class="saft-help-list" data-help-conversations>
                <summary style="cursor: pointer">{{ __('help.conversations') }} ({{ $conversations->count() }})</summary>
                @include('livewire.partials.help-conversations')
            </details>
        @else
            <nav class="saft-help-list" aria-label="{{ __('help.conversations') }}" data-help-conversations>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-plus" wire:click="newConversation" data-help-new>{{ __('help.new') }}</x-filament::button>
                @include('livewire.partials.help-conversations')
            </nav>
        @endif

        <div class="saft-help-chat">
            @if ($compact)
                <div style="display: flex; justify-content: space-between; align-items: center; gap: .5rem">
                    <strong style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $current?->title ?? __('help.new') }}</strong>
                    @if ($current)
                        <x-filament::button size="xs" color="gray" icon="heroicon-m-plus" wire:click="newConversation" data-help-new>{{ __('help.new') }}</x-filament::button>
                    @endif
                </div>
            @endif

            @if ($messages === [])
                <p class="saft-help-note" data-help-intro>{{ __('help.intro') }}</p>
            @endif

            @foreach ($messages as $message)
                @if ($message['role'] === 'user')
                    <div class="saft-help-msg user" data-help-question>{{ $message['text'] }}</div>
                @else
                    <div class="saft-help-msg assistant" data-help-answer>{!! $message['html'] !!}</div>
                @endif
            @endforeach

            <div wire:loading.flex wire:target="ask" class="saft-help-note" style="align-items: center; gap: .5rem" data-help-thinking>
                <x-filament::loading-indicator class="h-4 w-4" style="width: 1rem; height: 1rem" />
                {{ __('help.thinking') }}
            </div>

            @if ($error)
                <p style="color: var(--danger-600)" role="alert" data-help-error>{{ $error }}</p>
            @endif

            <form class="saft-help-input" wire:submit="ask" style="display: flex; flex-direction: column; gap: .5rem">
                <textarea wire:model="question"
                          maxlength="{{ \App\Help\HelpAssistant::MAX_QUESTION }}"
                          placeholder="{{ __('help.placeholder') }}"
                          aria-label="{{ __('help.placeholder') }}"
                          x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                          x-on:focus="if ($el.value === @js(__('help.prefill'))) $el.select()"
                          data-help-input></textarea>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: .75rem">
                    <span class="saft-help-note">{{ __('help.disclaimer') }}</span>
                    <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled" wire:target="ask" data-help-send>{{ __('help.send') }}</x-filament::button>
                </div>
            </form>
        </div>
    @endif
</div>
