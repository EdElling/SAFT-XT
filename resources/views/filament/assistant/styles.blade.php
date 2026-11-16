{{-- Gemeinsame Optik der KI-Kacheln (Auswertungs-Editor, Table-Designer). --}}
<style>
    .saft-assistant { border: 1px solid var(--primary-200); background: color-mix(in oklab, var(--primary-50) 60%, transparent); border-radius: .75rem; padding: .75rem; margin-bottom: 1rem; }
    .dark .saft-assistant { border-color: var(--primary-800); background: color-mix(in oklab, var(--primary-950) 50%, transparent); }
    .saft-assistant textarea { width: 100%; border: 1px solid var(--gray-300); border-radius: .5rem; padding: .5rem .65rem; background: var(--color-white, #fff); resize: vertical; }
    .dark .saft-assistant textarea { border-color: var(--gray-600); background: var(--gray-900); }
    .saft-assistant .row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: .5rem; }
    .saft-assistant .hint { font-size: .8rem; color: var(--gray-500); }
    .saft-assistant .reply { margin-top: .5rem; padding: .5rem .65rem; border-radius: .5rem; background: var(--color-white, #fff); border: 1px solid var(--gray-200); }
    .dark .saft-assistant .reply { background: var(--gray-900); border-color: var(--gray-700); }
    .saft-assistant .title { display: flex; align-items: center; gap: .4rem; font-weight: 600; margin-bottom: .35rem; }
    .saft-assistant .title svg { width: 1.25rem; height: 1.25rem; color: var(--primary-600); flex: none; }
    .dark .saft-assistant .title svg { color: var(--primary-400); }
</style>
