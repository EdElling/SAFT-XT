<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Kennzeichnet eine Art mit mehreren Phasen über dieselbe Menge (z. B. prüfen, dann schreiben), deren Fortschritt je
 * Phase von 0 bis 100 % zählen soll (B-176, seit API 1.11). Balken und Prozentzahl auf der Seite der Arbeit und in
 * „Meine Arbeiten“ rechnen dann mit den Zahlen der laufenden Phase statt mit „erledigt / Menge“ über alles. Den Text
 * zur Phase liefert DescribesProgress.
 *
 * @api
 */
interface ReportsPhaseProgress
{
    /**
     * @param  array<string, mixed>  $state  letzter gespeicherter Zustand (leer vor dem ersten Schritt)
     * @return array{0: int, 1: int}|null erledigt und Menge der laufenden Phase; null = wie üblich „erledigt / Menge“
     */
    public function phaseProgress(array $state, int $done, ?int $total): ?array;
}
