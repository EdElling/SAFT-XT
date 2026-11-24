<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Kennzeichnet eine Art, deren Schritte sich nicht teilen lassen und länger dauern als das Zeitbudget eines Treibers –
 * etwa eine Anfrage an die KI. Der Runner hebt vor jedem Schritt das Zeitlimit von PHP und die Dauer der Sperre auf
 * stepSeconds() an. Der Browser der startenden Person führt einen solchen Schritt nur aus, wenn sich das Zeitlimit
 * anheben lässt; sonst bleibt er dem Scheduler oder dem Queue-Worker.
 *
 * @api
 */
interface LongSteps
{
    /** Höchstdauer eines Schritts in Sekunden. */
    public function stepSeconds(): int;
}
