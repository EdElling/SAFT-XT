{{-- KI-Assistent: Beschreibung → Vorschlag im Editor; Rückfragen und Nachfragen im selben Gespräch. --}}
<section class="saft-assistant" data-report-assistant aria-label="{{ __('assistant.title') }}">
    @include('filament.assistant.styles')
    <label for="assistant-prompt" class="title">
        <x-filament::icon icon="heroicon-o-sparkles" />
        {{ __('assistant.title') }}
    </label>
    <textarea id="assistant-prompt" rows="2" maxlength="{{ \App\Reports\Assistant\ReportAssistant::MAX_PROMPT }}"
              wire:model="assistantPrompt" wire:keydown.ctrl.enter="askAssistant" wire:keydown.meta.enter="askAssistant"
              placeholder="{{ $assistantHistory === [] ? __('assistant.placeholder') : __('assistant.placeholder_followup') }}" data-assistant-prompt></textarea>

    @if ($assistantReply !== null)
        <div class="reply" data-assistant-reply="{{ $assistantReplyKind }}" role="status">
            @if ($assistantReplyKind === 'question')
                <strong>{{ __('assistant.question') }}</strong>
            @endif
            {{ $assistantReply }}
        </div>
    @endif

    <div class="row">
        <x-filament::button wire:click="askAssistant" wire:loading.attr="disabled" wire:target="askAssistant" icon="heroicon-o-sparkles" data-assistant-submit>
            <span wire:loading.remove wire:target="askAssistant">{{ $assistantHistory === [] ? __('assistant.submit') : __('assistant.submit_followup') }}</span>
            <span wire:loading wire:target="askAssistant">{{ __('assistant.working') }}</span>
        </x-filament::button>
        @if ($assistantHistory !== [])
            <x-filament::button color="gray" wire:click="resetAssistant" data-assistant-reset>{{ __('assistant.new_conversation') }}</x-filament::button>
        @endif
        <span class="hint">{{ __('assistant.remaining', ['count' => $assistantRemaining]) }}</span>
    </div>
    <p class="hint" style="margin-top: .4rem">{{ __('assistant.privacy', ['provider' => $assistantProvider]) }}</p>
</section>
