{{-- „Mit KI anpassen“: Gesprächsverlauf im Dialog – Wünsche, Rückfragen, Hinweise, Fehler und der aktuelle Vorschlag. --}}
@php /** @var list<array{role: string, text: string, lines?: list<string>}> $messages */ @endphp
{{-- Neuer Schlüssel je Nachricht: Alpine startet neu und scrollt zur neuesten Nachricht. --}}
<div class="saft-ai-conversation" data-table-assistant-conversation wire:key="ai-conversation-{{ count($messages) }}"
     x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">
    <style>
        .saft-ai-conversation { display: flex; flex-direction: column; gap: .5rem; max-height: 22rem; overflow-y: auto; font-size: .875rem; }
        .saft-ai-conversation .msg { padding: .5rem .65rem; border-radius: .5rem; border: 1px solid var(--gray-200); white-space: pre-line; }
        .dark .saft-ai-conversation .msg { border-color: var(--gray-700); }
        .saft-ai-conversation .msg.user { align-self: flex-end; max-width: 85%; background: var(--primary-50); border-color: var(--primary-200); }
        .dark .saft-ai-conversation .msg.user { background: color-mix(in oklab, var(--primary-500) 15%, transparent); border-color: var(--primary-800); }
        .saft-ai-conversation .msg.error { border-color: var(--danger-300); color: var(--danger-700); }
        .dark .saft-ai-conversation .msg.error { border-color: var(--danger-700); color: var(--danger-400); }
        .saft-ai-conversation .who { display: block; font-size: .75rem; font-weight: 600; color: var(--gray-500); margin-bottom: .15rem; }
        .saft-ai-conversation ul { list-style: disc; padding-inline-start: 1.25rem; margin-top: .25rem; white-space: normal; }
        .saft-ai-conversation .empty { color: var(--gray-500); }
    </style>

    @forelse ($messages as $message)
        <div class="msg {{ $message['role'] }}" data-ai-message="{{ $message['role'] }}">
            <span class="who">{{ $message['role'] === 'user' ? __('tables_assistant.conversation.you') : __('tables_assistant.conversation.ai') }}</span>
            {{ $message['text'] }}
            @if (($message['lines'] ?? []) !== [])
                <ul data-table-assistant-preview>
                    @foreach ($message['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="empty">{{ __('tables_assistant.edit_help') }}</p>
    @endforelse
</div>
