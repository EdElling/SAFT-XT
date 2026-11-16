<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Verbrauch der KI-Funktionen: Tageslimit je Person (gemeinsam für alle Assistenten) und Protokoll jeder Anfrage
 * (Änderungsprotokoll, Ereignis „assistant“, mit Bereich, Text, Ergebnis, Modell und Tokens).
 */
final class AiUsage
{
    public const string EVENT = 'assistant';

    public static function usedToday(User $user): int
    {
        return Activity::query()->where('event', self::EVENT)
            ->where('causer_type', $user->getMorphClass())->where('causer_id', $user->getKey())
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    public static function remainingToday(User $user): int
    {
        return max(0, app(AiConfig::class)->dailyLimit($user) - self::usedToday($user));
    }

    /** Meldung, wenn heute nichts mehr geht, sonst null. */
    public static function limitMessage(User $user): ?string
    {
        if (self::remainingToday($user) > 0) {
            return null;
        }

        $limit = app(AiConfig::class)->dailyLimit($user);

        return $limit === 0 ? __('assistant.errors.blocked') : __('assistant.errors.daily_limit', ['limit' => $limit]);
    }

    /** @param  array{in: int, out: int, cache: int}  $usage */
    public static function log(User $user, string $area, string $text, string $status, array $usage, string $model, ?string $message = null): void
    {
        activity($area)->causedBy($user)->event(self::EVENT)
            ->withChanges(['attributes' => array_filter([
                'anfrage' => $text,
                'ergebnis' => $status,
                'meldung' => $message,
                'modell' => $model,
                'tokens_ein' => $usage['in'],
                'tokens_aus' => $usage['out'],
                'tokens_cache' => $usage['cache'],
            ], static fn (mixed $v): bool => $v !== null)])
            ->log(self::EVENT);
    }
}
