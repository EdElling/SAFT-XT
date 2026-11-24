<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Kennzeichnet eine Art, die ihren Fortschritt selbst in Worte fasst, weil „erledigt / Menge“ allein in die Irre
 * führte – etwa bei zwei Phasen über dieselben Zeilen (Datenimport: prüfen, dann einspielen). Die Seite der Arbeit und
 * die Liste zeigen den Text statt der Zahlen; null heisst: die Zahlen wie üblich. Der Prozentwert kommt weiter aus
 * „erledigt“ und „Menge“.
 *
 * @api
 */
interface DescribesProgress
{
    /**
     * @param  array<string, mixed>  $state  letzter gespeicherter Zustand (leer vor dem ersten Schritt)
     */
    public function progress(array $state, int $done, ?int $total): ?string;
}
