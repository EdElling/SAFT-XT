{{--
    Warnung vor dem Abmelden nach Inaktivität (B-162). Das Skript merkt sich Klicks, Tasten, Scrollen und Berührungen
    und kennzeichnet damit die nächste Livewire-Anfrage (Header X-Saft-Activity); Polls ohne Bedienung bleiben
    ungekennzeichnet und zählen nicht. Bedienung ohne Anfrage (langes Tippen) meldet es höchstens einmal je Minute.
    Kurz vor Ablauf fragt es den Server nach der Restzeit (ein anderer Tab kann bedient worden sein), zeigt die Warnung
    und lädt nach Ablauf die Seite neu: Dann meldet LogoutWhenIdle ab und leitet zur Anmeldung.
    Das Skript läuft einmal je Seite (data-navigate-once); die Markierung mit der Restzeit kommt bei jeder Navigation neu.
--}}
@php($idle = app(\App\Auth\IdleTimeout::class))
<div
    hidden
    data-saft-idle
    data-remaining="{{ $idle->remainingSeconds(request()) }}"
    data-limit="{{ (int) $idle->limitMinutes() * 60 }}"
    data-status-url="{{ route('session.idle') }}"
    data-activity-url="{{ route('session.activity') }}"
></div>
<script data-navigate-once>
    window.saftIdle ??= (() => {
        const WARNING = {{ \App\Auth\IdleTimeout::WARNING_SECONDS }};
        const HEADER = @js(\App\Auth\IdleTimeout::HEADER);
        const MODAL = 'saft-idle-warning';
        const state = { pending: false, lastSent: Date.now(), deadline: 0, timer: null, tick: null, warning: false };
        const marker = () => document.querySelector('[data-saft-idle]');

        const schedule = (remaining) => {
            clearTimeout(state.timer);
            if (!Number.isFinite(remaining)) return;
            state.deadline = Date.now() + remaining * 1000;
            state.timer = setTimeout(check, Math.max(0, remaining - WARNING) * 1000);
        };

        const request = async (method, url) => {
            try {
                const response = await fetch(url, {
                    method,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });
                if (!response.ok || response.redirected) {
                    window.location.reload();
                    return undefined;
                }
                return (await response.json()).remaining;
            } catch (e) {
                return undefined;
            }
        };

        const countdown = () => {
            const seconds = Math.max(0, Math.round((state.deadline - Date.now()) / 1000));
            document.querySelectorAll('[data-saft-idle-countdown]').forEach((el) => {
                el.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            });
        };

        const show = () => {
            if (!state.warning) {
                state.warning = true;
                window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: MODAL } }));
                state.tick = setInterval(countdown, 1000);
            }
            countdown();
        };

        const hide = () => {
            if (state.warning) {
                state.warning = false;
                clearInterval(state.tick);
                window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: MODAL } }));
            }
        };

        const check = async () => {
            const remaining = await request('GET', marker()?.dataset.statusUrl);
            if (remaining === undefined || remaining === null) return;
            if (remaining > WARNING + 5) {
                hide();
                schedule(remaining);
                return;
            }
            if (remaining <= 0) {
                window.location.reload();
                return;
            }
            state.deadline = Date.now() + remaining * 1000;
            show();
            clearTimeout(state.timer);
            state.timer = setTimeout(check, remaining * 1000 + 1000);
        };

        const counted = () => {
            state.pending = false;
            state.lastSent = Date.now();
            hide();
            const limit = parseInt(marker()?.dataset.limit ?? '0', 10);
            if (limit > 0) schedule(limit);
        };

        const report = async () => {
            counted();
            const remaining = await request('POST', marker()?.dataset.activityUrl);
            if (Number.isFinite(remaining)) schedule(remaining);
        };

        const interacted = () => {
            state.pending = true;
            if (state.warning) report();
        };

        ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((name) => document.addEventListener(name, interacted, { capture: true, passive: true }));

        // Bedienung ohne eigene Anfrage (z. B. langes Tippen) höchstens einmal je Minute melden.
        setInterval(() => {
            if (state.pending && Date.now() - state.lastSent > 60000) report();
        }, 15000);

        const intercept = () => window.Livewire.interceptRequest(({ request }) => {
            if (!state.pending) return;
            request.options.headers[HEADER] = '1';
            counted();
        });
        window.Livewire?.interceptRequest ? intercept() : document.addEventListener('livewire:init', intercept);

        // Navigation im SPA-Modus ist Bedienung; die neue Markierung bringt die Restzeit vom Server.
        const start = () => schedule(parseInt(marker()?.dataset.remaining ?? '', 10));
        document.addEventListener('livewire:navigated', () => { hide(); start(); });
        start();

        return { report, check, state };
    })();
</script>
<x-filament::modal id="saft-idle-warning" icon="heroicon-o-clock" icon-color="warning" width="md" :close-by-clicking-away="false" data-saft-idle-warning>
    <x-slot name="heading">{{ __('auth_admin.idle.warning_title') }}</x-slot>
    <p>{!! str_replace(':time', '<strong data-saft-idle-countdown>5:00</strong>', e(__('auth_admin.idle.warning_body'))) !!}</p>
    <x-slot name="footerActions">
        <x-filament::button x-on:click="window.saftIdle.report()" data-saft-idle-continue>{{ __('auth_admin.idle.continue') }}</x-filament::button>
    </x-slot>
</x-filament::modal>
