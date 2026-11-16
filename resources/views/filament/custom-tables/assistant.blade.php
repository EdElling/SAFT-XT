{{-- KI-Assistent beim Anlegen einer Tabelle: Beschreibung → vorausgefülltes Formular; Rückfragen und Nachfragen. --}}
<section class="saft-assistant" data-table-assistant aria-label="{{ __('tables_assistant.title') }}">
    @include('filament.assistant.styles')
    <label for="table-assistant-prompt" class="title">
        <x-filament::icon icon="heroicon-o-sparkles" />
        {{ __('tables_assistant.title') }}
    </label>
    <textarea id="table-assistant-prompt" rows="2" maxlength="2000" wire:model="assistantPrompt" data-table-assistant-prompt
              placeholder="{{ $history === [] ? __('tables_assistant.placeholder') : __('tables_assistant.placeholder_followup') }}"></textarea>

    @if ($reply !== null)
        <div class="reply" data-table-assistant-reply="{{ $replyKind }}" role="status">
            @if ($replyKind === 'question')
                <strong>{{ __('assistant.question') }}</strong>
            @endif
            {{ $reply }}
        </div>
    @endif

    <div class="row">
        <x-filament::button wire:click="askTableAssistant" wire:loading.attr="disabled" wire:target="askTableAssistant" icon="heroicon-o-sparkles" data-table-assistant-submit>
            <span wire:loading.remove wire:target="askTableAssistant">{{ $history === [] ? __('assistant.submit') : __('assistant.submit_followup') }}</span>
            <span wire:loading wire:target="askTableAssistant">{{ __('assistant.working') }}</span>
        </x-filament::button>
        @if ($history !== [])
            <x-filament::button color="gray" wire:click="resetTableAssistant">{{ __('assistant.new_conversation') }}</x-filament::button>
        @endif
        <span class="hint">{{ __('assistant.remaining', ['count' => $remaining]) }}</span>
    </div>
    <p class="hint" style="margin-top: .4rem">{{ __('tables_assistant.privacy', ['provider' => $provider]) }}</p>
</section>
