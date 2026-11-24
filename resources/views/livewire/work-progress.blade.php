{{-- Fortschritt einer Arbeit (App\Livewire\WorkProgress); fragt nach, solange sie offen ist. --}}
<div @if ($item->isOpen()) wire:poll.2s="advance" @endif data-work-progress data-status="{{ $item->status }}">
    <x-filament::section>
        <x-slot name="heading">
            <x-filament::badge :color="$color" style="display: inline-flex;">{{ __('work.status.'.$item->status) }}</x-filament::badge>
            <span style="margin-left: .5rem;" data-work-count>{{ $progress }}</span>
        </x-slot>

        @if ($canCancel)
            <x-slot name="afterHeader">
                    <x-filament::button color="danger" size="sm" wire:click="cancel" wire:confirm="{{ __('work.actions.cancel_heading') }}" data-work-cancel>
                        {{ $item->cancel_requested_at === null ? __('work.actions.cancel') : __('work.actions.cancelling') }}
                    </x-filament::button>
            </x-slot>
        @endif

        <div style="height: .5rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden;" role="progressbar"
            aria-valuemin="0" aria-valuemax="100" @if ($item->percent() !== null) aria-valuenow="{{ $item->percent() }}" @endif>
            <div style="height: 100%; width: {{ $item->percent() ?? ($item->isOpen() ? 5 : 0) }}%; background: var(--{{ $color === 'gray' ? 'primary' : $color }}-600); transition: width .3s;"></div>
        </div>

        <div style="margin-top: .75rem; font-size: .875rem; display: flex; flex-direction: column; gap: .5rem;">
            @if ($item->isOpen())
                <p style="color: var(--gray-500);">{{ $drives ? __('work.progress.driving') : __('work.progress.background') }}</p>
            @endif

            @if (filled($item->message))
                <p data-work-message>{{ $item->message }}</p>
            @endif

            @if ($item->resultFile() !== null)
                <div>
                    <x-filament::button tag="a" :href="route('work.download', $item->uuid)" icon="heroicon-o-arrow-down-tray" size="sm" data-work-download>
                        {{ __('work.actions.download') }}
                    </x-filament::button>
                    <span style="margin-left: .5rem; color: var(--gray-500);">{{ __('work.progress.expires', ['time' => $item->expires_at?->format('d.m.Y H:i')]) }}</span>
                </div>
            @endif
        </div>
    </x-filament::section>
</div>
