<?php

declare(strict_types=1);

namespace App\Plugins\Api\Events;

/**
 * Nach dem Speichern (Anlegen oder Ändern), noch in derselben Transaktion.
 *
 * @api
 */
final readonly class RecordSaved extends RecordEvent
{
    /** Wird der Datensatz neu angelegt? */
    public function creating(): bool
    {
        return $this->creating;
    }
}
