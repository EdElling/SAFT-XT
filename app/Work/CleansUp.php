<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Kennzeichnet eine Art, die hinterlassene Spuren einer abgebrochenen oder fehlgeschlagenen Arbeit zurücknimmt (halb
 * angelegte Datensätze, eine Zwischentabelle). Der Runner ruft abort() vor dem Abschluss als „fehlgeschlagen“ oder
 * „abgebrochen“ als die startende Person – auch beim sofortigen Abbruch einer wartenden Arbeit, dann mit leerem Zustand.
 * $step trägt Parameter, den letzten gespeicherten Zustand und den Ordner der Arbeit (ohne Zeitbudget); dessen Dateien
 * löscht der Runner danach selbst.
 *
 * @api
 */
interface CleansUp
{
    public function abort(WorkStep $step, string $status): void;
}
