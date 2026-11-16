{{-- Liste der gespeicherten Unterhaltungen (Hilfe-Assistent). --}}
@forelse ($conversations as $conversation)
    <div @class(['item', 'active' => $conversation->id === $conversationId]) wire:key="help-conversation-{{ $conversation->id }}">
        <button type="button" class="open" wire:click="open({{ $conversation->id }})" title="{{ $conversation->title }}" data-help-open="{{ $conversation->id }}">{{ $conversation->title }}</button>
        <x-filament::icon-button icon="heroicon-m-trash" color="gray" size="xs" :label="__('help.delete')" :tooltip="__('help.delete')"
                                 wire:click="delete({{ $conversation->id }})" wire:confirm="{{ __('help.delete_confirm') }}" data-help-delete="{{ $conversation->id }}" />
    </div>
@empty
    <small>{{ __('help.none') }}</small>
@endforelse
