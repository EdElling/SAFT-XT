<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Zeitlimit von PHP (max_execution_time) für die Treiber: ein Budget darunter halten, es für lange Schritte anheben.
 * Eigene Klasse, damit Tests ein Umfeld nachstellen können, in dem sich das Limit nicht anheben lässt.
 */
class TimeLimit
{
    /** Zeitlimit in Sekunden; null ohne Limit (Konsole, 0). */
    public function limit(): ?int
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? $limit : null;
    }

    /** Ein Budget unter dem Zeitlimit: mindestens 1 s, höchstens Limit − $margin. */
    public function budget(float $wanted, int $margin = 5): float
    {
        $limit = $this->limit();

        return max(1.0, $limit === null ? $wanted : min($wanted, $limit - $margin));
    }

    /** Das Limit auf mindestens so viele Sekunden anheben; false, wenn PHP das nicht erlaubt. */
    public function extend(int $seconds): bool
    {
        $limit = $this->limit();

        if ($limit === null || $limit >= $seconds) {
            return true;
        }

        return @set_time_limit($seconds);
    }
}
