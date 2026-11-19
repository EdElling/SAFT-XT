{{-- Übersicht vor dem Entfernen einer App (B-107, Etappe 3): was verloren geht und was es verhindert. --}}
@php /** @var array $overview */ /** @var list<string> $blockers */ @endphp
<div class="space-y-3 text-sm" data-app-removal-overview>
    @if ($blockers !== [])
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-app-removal-blockers>
            <p class="font-semibold">{{ __('apps.removal.blocked') }}</p>
            <ul class="list-disc ps-5">
                @foreach ($blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p>{{ __('apps.removal.overview_intro') }}</p>
    <ul class="list-disc ps-5">
        @forelse ($overview['tables'] as $table)
            <li>{{ __('apps.removal.table', ['table' => $table['label'], 'name' => $table['name'], 'records' => $table['records'] ?? '?']) }}</li>
        @empty
            <li>{{ __('apps.removal.no_tables') }}</li>
        @endforelse
        @foreach ($overview['connected'] as $label)
            <li>{{ __('apps.removal.connected', ['table' => $label]) }}</li>
        @endforeach
        <li>{{ __('apps.removal.counts', ['menus' => $overview['menus'], 'reports' => $overview['reports'], 'links' => $overview['report_links'], 'tasks' => $overview['tasks']]) }}</li>
        @foreach ($overview['roles'] as $role)
            <li>{{ __('apps.removal.role', ['role' => $role['name'], 'members' => $role['members']]) }}</li>
        @endforeach
        @if ($overview['manual'])
            <li>{{ __('apps.removal.manual') }}</li>
        @endif
    </ul>

    @if ($overview['users_without_app'] > 0)
        <p class="font-semibold text-warning-700 dark:text-warning-400">{{ trans_choice('apps.removal.users_without_app', $overview['users_without_app'], ['count' => $overview['users_without_app']]) }}</p>
    @endif
    @if ($overview['plugins'] !== [])
        <p>{{ __('apps.removal.plugins', ['plugins' => implode(', ', $overview['plugins'])]) }}</p>
    @endif
    <p>{{ __('apps.removal.backup_and_delay', ['minutes' => \App\Apps\AppRemover::DELAY_MINUTES]) }}</p>
</div>
