<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\Plugins\Api\Events\RecordEvent;
use Closure;

/**
 * Ein Plugin hört auf Änderungen einer Tabelle (SaftPlugin::recordListeners()). Der Handler erhält das Ereignis;
 * er läuft bei jeder Änderung – über die Datenansicht, den Import, die Schnittstelle oder geplante Aufgaben.
 *
 * @api
 */
final readonly class RecordListener
{
    /**
     * @param  string  $table  interner Name der Tabelle
     * @param  class-string<RecordEvent>  $event  z. B. RecordSaved::class
     * @param  Closure(RecordEvent): void|class-string  $handler  Closure oder Klasse mit __invoke(RecordEvent)
     */
    public function __construct(
        public string $table,
        public string $event,
        public Closure|string $handler,
    ) {}
}
