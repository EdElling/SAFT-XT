<?php

declare(strict_types=1);

namespace App\Plugins\Api\Events;

/**
 * Vor dem Speichern (Anlegen oder Ändern), nach den Vorgaben (fortlaufend, Übernehmen aus) und vor den berechneten
 * Feldern. Ein Listener kann Werte setzen ($event->record->setAttribute()) oder mit RecordRejected ablehnen.
 *
 * @api
 */
final readonly class RecordSaving extends RecordEvent
{
    /** Wird der Datensatz neu angelegt? */
    public function creating(): bool
    {
        return $this->creating;
    }
}
