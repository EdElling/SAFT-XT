{{-- Änderungsplan beim Aktualisieren einer App (B-107, Etappe 6): Versionen, Hinderungsgründe, Hinweise, Schnittstellen,
     gesperrte Schritte und Unterschiede, die erst Etappe 6b ausführt. Die wählbaren Schritte folgen als Auswahlliste. --}}
@php
    /** @var \App\Apps\Update\ChangePlan $plan */
    $blocked = array_values(array_filter($plan->steps, static fn ($s) => $s->executable() && $s->blocked !== null));
@endphp
<div class="space-y-3 text-sm" data-app-update-plan>
    @if ($plan->target !== [])
        <p data-app-update-versions>
            {{ __('apps.update.versions', ['from' => $plan->fromVersion ?? '–', 'base' => $plan->hasBaseline ? ($plan->baseVersion ?? '–') : __('apps.update.no_baseline'), 'to' => (string) ($plan->target['app']['version'] ?? '–')]) }}
        </p>
    @endif

    @if ($plan->blockers !== [])
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-app-update-blockers>
            <p class="font-semibold">{{ __('apps.install.blocked') }}</p>
            <ul style="list-style: disc; padding-inline-start: 1.5rem;">
                @foreach ($plan->blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plan->warnings !== [])
        <div class="rounded-lg bg-warning-50 p-3 text-warning-800 dark:bg-warning-500/10 dark:text-warning-400" data-app-update-warnings>
            <p class="font-semibold">{{ __('apps.install.notes') }}</p>
            <ul style="list-style: disc; padding-inline-start: 1.5rem;">
                @foreach ($plan->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plan->requires !== [])
        <div>
            <p class="font-semibold">{{ __('apps.install.requires') }}</p>
            <ul style="list-style: disc; padding-inline-start: 1.5rem;">
                @foreach ($plan->requires as $required)
                    <li>{{ $required }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plan->canApply() && $plan->target !== [] && $plan->isEmpty())
        <p class="text-gray-500" data-app-update-none>{{ __('apps.update.none') }}</p>
    @endif

    @if ($plan->local !== [])
        <div data-app-update-local>
            <p class="font-semibold">{{ __('apps.update.local') }}</p>
            <ul style="list-style: disc; padding-inline-start: 1.5rem;">
                @foreach ($plan->local as $entry)
                    <li>{{ $entry }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($blocked !== [])
        <div data-app-update-blocked>
            <p class="font-semibold">{{ __('apps.update.blocked') }}</p>
            <ul style="list-style: disc; padding-inline-start: 1.5rem;">
                @foreach ($blocked as $step)
                    <li>{{ \App\Apps\Update\ChangeText::summary($step) }} – {{ $step->blocked }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plan->later() !== [])
        <div data-app-update-later>
            <p class="font-semibold">{{ __('apps.update.later') }}</p>
            <ul style="list-style: disc; padding-inline-start: 1.5rem;">
                @foreach ($plan->later() as $step)
                    <li>
                        {{ \App\Apps\Update\ChangeText::summary($step) }}
                        @if ($step->conflict) <span class="text-primary-600 dark:text-primary-400">({{ __('apps.update.conflict') }})</span>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
