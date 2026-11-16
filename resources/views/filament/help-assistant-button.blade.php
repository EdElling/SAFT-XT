{{-- Knopf „?“ in der Kopfzeile: öffnet den Hilfe-Assistenten seitlich und nennt ihm die aktuelle Seite. --}}
<div data-help-button-wrapper>
    <x-filament::icon-button
        icon="heroicon-o-question-mark-circle"
        color="gray"
        size="lg"
        :label="__('help.button')"
        :tooltip="__('help.button')"
        x-on:click="Livewire.dispatch('help-page', { title: document.title, path: location.pathname }); $dispatch('open-modal', { id: 'saft-help-assistant' })"
        data-help-button
    />

    <x-filament::modal id="saft-help-assistant" slide-over width="xl" :heading="__('help.title')" :description="__('help.button_help')">
        @livewire(\App\Livewire\HelpAssistantChat::class, ['compact' => true], key('help-assistant-panel'))
    </x-filament::modal>
</div>
