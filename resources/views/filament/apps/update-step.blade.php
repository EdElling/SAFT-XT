{{-- Ein Schritt des Änderungsplans (B-107, Etappe 6): Einstufung, Konflikt, Text, Gründe. --}}
@php
    /** @var \App\Apps\Update\ChangeStep $step */
    $colors = [
        'safe' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
        'risky' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
        'destructive' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400',
    ];
@endphp
<span data-update-step="{{ $step->id }}" data-update-level="{{ $step->level }}">
    <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $colors[$step->level] }}">{{ __('apps.update.level.'.$step->level) }}@unless ($step->conflict):@endunless</span>
    @if ($step->conflict)
        <span class="rounded bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400" data-update-conflict>{{ __('apps.update.conflict') }}:</span>
    @endif
    {{ \App\Apps\Update\ChangeText::summary($step) }}
    @if ($step->notes !== [])
        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ implode(' ', $step->notes) }}</span>
    @endif
</span>
