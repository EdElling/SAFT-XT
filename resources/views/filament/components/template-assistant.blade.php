{{--
    KI-Assistent für Dokumentvorlagen (AssistsDocumentTemplate): Beschreibung → Empfänger, Inhalt und Dateiname im
    Formular; Rückfragen und Nachfragen im selben Gespräch; „Rückgängig“ stellt den Stand vor dem Vorschlag her.
--}}
@php($lw = $getLivewire())
@php($hasTable = filled(data_get($lw, 'data.custom_table_id')))
<section class="saft-assistant" data-template-assistant aria-label="{{ __('documents.assistant.title') }}">
    @include('filament.assistant.styles')
    <label for="template-assistant-prompt" class="title">
        <x-filament::icon icon="heroicon-o-sparkles" />
        {{ __('documents.assistant.title') }}
    </label>
    @if (! $hasTable)
        <p class="hint">{{ __('documents.assistant.choose_table') }}</p>
    @else
        <textarea id="template-assistant-prompt" rows="2" maxlength="{{ \App\Documents\Assistant\TemplateAssistant::MAX_PROMPT }}"
                  wire:model="assistantPrompt" wire:keydown.ctrl.enter="askAssistant" wire:keydown.meta.enter="askAssistant"
                  placeholder="{{ $lw->assistantHistory === [] ? __('documents.assistant.placeholder') : __('documents.assistant.placeholder_followup') }}" data-assistant-prompt></textarea>

        @if ($lw->assistantReply !== null)
            <div class="reply" data-assistant-reply="{{ $lw->assistantReplyKind }}" role="status">
                @if ($lw->assistantReplyKind === 'question')
                    <strong>{{ __('assistant.question') }}</strong>
                @endif
                {{ $lw->assistantReply }}
            </div>
        @endif

        <div class="row">
            <x-filament::button wire:click="askAssistant" wire:loading.attr="disabled" wire:target="askAssistant" icon="heroicon-o-sparkles" data-assistant-submit>
                <span wire:loading.remove wire:target="askAssistant">{{ $lw->assistantHistory === [] ? __('assistant.submit') : __('assistant.submit_followup') }}</span>
                <span wire:loading wire:target="askAssistant">{{ __('assistant.working') }}</span>
            </x-filament::button>
            @if ($lw->assistantUndo !== null)
                <x-filament::button color="gray" icon="heroicon-o-arrow-uturn-left" wire:click="undoAssistant" data-assistant-undo>{{ __('documents.assistant.undo') }}</x-filament::button>
            @endif
            @if ($lw->assistantHistory !== [])
                <x-filament::button color="gray" wire:click="resetAssistant" data-assistant-reset>{{ __('assistant.new_conversation') }}</x-filament::button>
            @endif
            <span class="hint">{{ __('assistant.remaining', ['count' => $lw->assistantRemaining()]) }}</span>
        </div>
        <p class="hint" style="margin-top: .4rem">{{ __('documents.assistant.privacy', ['provider' => app(\App\Ai\AiConfig::class)->providerLabel()]) }}</p>
    @endif
</section>
