{{-- Vorschau beim Installieren einer App (B-107, Etappe 4b): was angelegt wird, was es verhindert, Hinweise, Diagramm. --}}
@php
    /** @var \App\Apps\Definition\InstallAnalysis $analysis */
    $default = (string) config('locales.default');
    $name = (string) (($app['name'] ?? [])[app()->getLocale()] ?? ($app['name'] ?? [])[$default] ?? '');
@endphp
<div class="space-y-3 text-sm" data-app-install-preview>
    @if ($name !== '')
        <p class="text-base font-semibold" data-app-install-name>
            {{ $name }}@if (filled($app['version'] ?? null)) · {{ __('apps.version') }} {{ $app['version'] }}@endif
            @if (filled($app['prefix'] ?? null)) <span class="font-normal text-gray-500">({{ __('apps.prefix') }}: {{ $app['prefix'] }})</span>@endif
        </p>
    @endif

    @if ($analysis->blockers !== [])
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-app-install-blockers>
            <p class="font-semibold">{{ __('apps.install.blocked') }}</p>
            <ul class="list-disc ps-5">
                @foreach ($analysis->blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($analysis->counts !== [])
        <div>
            <p class="font-semibold">{{ __('apps.install.creates') }}</p>
            <ul class="list-disc ps-5" data-app-install-counts>
                @foreach ($analysis->counts as $part => $count)
                    @if ($count > 0)
                        <li>{{ trans_choice('apps.install.count.'.$part, $count, ['count' => $count]) }}</li>
                    @endif
                @endforeach
            </ul>
            <p class="text-gray-500">{{ __('apps.install.inactive_hint') }}</p>
        </div>
    @endif

    @if ($analysis->warnings !== [])
        <div class="rounded-lg bg-warning-50 p-3 text-warning-800 dark:bg-warning-500/10 dark:text-warning-400" data-app-install-warnings>
            <p class="font-semibold">{{ __('apps.install.notes') }}</p>
            <ul class="list-disc ps-5">
                @foreach ($analysis->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($diagram !== null)
        @include('filament.partials.data-model-diagram', $diagram)
    @endif
</div>
