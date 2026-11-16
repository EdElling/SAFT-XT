{{-- Hinweisband während der „Ansicht als Rolle“: auf jeder Seite, mit „Beenden“ (Formular außerhalb des Panels). --}}
<div role="status" data-role-simulation-banner style="display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; justify-content: center; padding: .5rem 1rem; background: var(--warning-100, #fef3c7); color: var(--warning-900, #78350f); border-bottom: 1px solid var(--warning-300, #fcd34d); font-size: .875rem; position: relative; z-index: 30">
    <span><strong>{{ trans_choice('role_simulation.banner', $roles->count(), ['roles' => $roles->map(fn ($r) => __('role_simulation.quoted', ['role' => $r->name]))->join(', ')]) }}</strong> {{ __('role_simulation.banner_hint') }}</span>
    <form method="POST" action="{{ route('role-simulation.stop') }}" style="display: inline">
        @csrf
        <button type="submit" style="font-weight: 600; text-decoration: underline; cursor: pointer; background: none; border: 0; color: inherit">{{ __('role_simulation.stop') }}</button>
    </form>
</div>
