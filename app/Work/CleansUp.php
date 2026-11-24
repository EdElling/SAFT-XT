<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Kennzeichnet eine Art, die hinter sich aufräumt, wenn die Arbeit fehlschlägt oder abgebrochen wird: halb kopierte
 * Datenstände entfernen, angelegte Datensätze zurücknehmen. Der Runner ruft abort() mit dem letzten gespeicherten
 * Zustand, bevor die Arbeit ihren Endstatus bekommt – als startende Person, auch beim sofortigen Abbruch einer Arbeit,
 * an der gerade kein Treiber arbeitet. Ergebnisdateien im Ordner der Arbeit löscht der Runner selbst.
 *
 * @api
 */
interface CleansUp
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $state  letzter gespeicherter Zustand (leer, wenn noch kein Schritt lief)
     * @param  string  $status  WorkItem::FAILED oder WorkItem::CANCELLED
     */
    public function abort(array $parameters, array $state, string $status): void;
}
