<?php

declare(strict_types=1);

namespace App\Auth;

use App\Settings\AuthSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Abmelden nach Inaktivität (B-162): Als Bedienung zählen Seitenaufrufe und Livewire-Anfragen, die das Skript im
 * Browser nach einem Klick oder einer Taste mit dem Header {@see self::HEADER} kennzeichnet – nicht die Abfrage der
 * Benachrichtigungen und andere Polls. Die letzte Bedienung steht in der Sitzung und in einem verschlüsselten Cookie,
 * zusammen mit dem Zeitpunkt der Anmeldung; über das Cookie prüft LogoutWhenIdle eine Wiederanmeldung durch
 * „Angemeldet bleiben“ (Leerlauf-Grenze und Höchstdauer ab der Anmeldung).
 */
final class IdleTimeout
{
    public const string SESSION_KEY = 'saft_idle.last_activity';

    public const string COOKIE = 'saft_activity';

    public const string HEADER = 'X-Saft-Activity';

    /** Warnung so viele Sekunden vor dem Abmelden. */
    public const int WARNING_SECONDS = 300;

    /** Parameter der Anmeldeseite nach dem Abmelden wegen Inaktivität. */
    public const string LOGIN_QUERY = 'expired';

    /** @var list<int> Auswahl der Leerlauf-Grenze in Minuten. */
    public const array LIMIT_OPTIONS = [15, 30, 60, 120, 240, 480, 720, 1440];

    /** @var list<int> Auswahl der Höchstdauer von „Angemeldet bleiben“ in Stunden. */
    public const array REMEMBER_OPTIONS = [1, 4, 8, 12, 24];

    public function __construct(private readonly AuthSettings $settings) {}

    public function limitMinutes(): ?int
    {
        $minutes = $this->settings->idle_timeout_minutes;

        return $minutes !== null && $minutes > 0 ? $minutes : null;
    }

    public function rememberEnabled(): bool
    {
        return $this->settings->remember_me_enabled;
    }

    public function rememberMinutes(): int
    {
        return max(1, $this->settings->remember_me_hours) * 60;
    }

    /** Die Leerlauf-Grenze als Text („4 Stunden“), leer ohne Grenze. */
    public function limitLabel(): string
    {
        $limit = $this->limitMinutes();

        return $limit === null ? '' : self::durationLabel($limit);
    }

    public static function durationLabel(int $minutes): string
    {
        return $minutes % 60 === 0
            ? trans_choice('auth_admin.idle.hours', intdiv($minutes, 60), ['count' => intdiv($minutes, 60)])
            : trans_choice('auth_admin.idle.minutes', $minutes, ['count' => $minutes]);
    }

    /** Neue Anmeldung (Passwort, Zwei-Faktor, SSO): Bedienung und Anmeldezeitpunkt jetzt. */
    public function startSession(): void
    {
        $now = now()->getTimestamp();
        session()->put(self::SESSION_KEY, $now);
        $this->queueCookie($now, $now);
    }

    /** Diese Anfrage war Bedienung. */
    public function touch(Request $request): void
    {
        $now = now()->getTimestamp();
        $request->session()->put(self::SESSION_KEY, $now);

        $cookie = $this->cookie($request);

        // Das Cookie nur einmal je Minute neu setzen, nicht bei jedem Klick.
        if ($cookie === null || $now - $cookie['activity'] >= 60) {
            $this->queueCookie($now, $cookie['login'] ?? $now);
        }
    }

    /** Zählt diese Anfrage als Bedienung? Livewire nur mit Kennzeichen aus dem Browser, die Restzeit-Abfrage nie. */
    public function countsAsActivity(Request $request): bool
    {
        if ($request->routeIs('session.idle')) {
            return false;
        }

        if ($request->hasHeader('X-Livewire')) {
            return $request->hasHeader(self::HEADER);
        }

        return true;
    }

    /** Sekunden bis zum Abmelden; null ohne Grenze. Fehlt der Zeitpunkt (Sitzung von vor B-162), gilt jetzt. */
    public function remainingSeconds(Request $request): ?int
    {
        $limit = $this->limitMinutes();

        if ($limit === null) {
            return null;
        }

        $last = $request->session()->get(self::SESSION_KEY);

        if (! is_int($last)) {
            return $limit * 60;
        }

        return max(0, $last + $limit * 60 - now()->getTimestamp());
    }

    public function isExpired(Request $request): bool
    {
        return $this->remainingSeconds($request) === 0;
    }

    /**
     * Grund, eine Wiederanmeldung durch „Angemeldet bleiben“ abzulehnen, oder null. Zugelassen nur, wenn angeboten, die
     * Anmeldung höchstens die eingestellte Dauer zurückliegt und die letzte Bedienung innerhalb der Leerlauf-Grenze.
     *
     * @return 'remember'|'idle'|null
     */
    public function rememberedRejection(Request $request): ?string
    {
        $cookie = $this->cookie($request);
        $now = now()->getTimestamp();

        if (! $this->rememberEnabled() || $cookie === null || $now - $cookie['login'] > $this->rememberMinutes() * 60) {
            return 'remember';
        }

        $limit = $this->limitMinutes();

        if ($limit !== null && $now - $cookie['activity'] > $limit * 60) {
            return 'idle';
        }

        return null;
    }

    /** Nach zugelassener Wiederanmeldung: letzte Bedienung aus dem Cookie in die neue Sitzung. */
    public function adoptRemembered(Request $request): void
    {
        $cookie = $this->cookie($request);

        if ($cookie !== null) {
            $request->session()->put(self::SESSION_KEY, $cookie['activity']);
        }
    }

    public function forgetCookie(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /**
     * @return array{activity: int, login: int}|null
     */
    private function cookie(Request $request): ?array
    {
        $value = json_decode((string) $request->cookie(self::COOKIE), true);

        if (! is_array($value) || ! is_int($value['a'] ?? null) || ! is_int($value['l'] ?? null)) {
            return null;
        }

        return ['activity' => $value['a'], 'login' => $value['l']];
    }

    private function queueCookie(int $activity, int $login): void
    {
        // Lebt so lange wie „Angemeldet bleiben“ höchstens gilt; danach ist es ohnehin wertlos.
        Cookie::queue(self::COOKIE, (string) json_encode(['a' => $activity, 'l' => $login]), $this->rememberMinutes());
    }
}
