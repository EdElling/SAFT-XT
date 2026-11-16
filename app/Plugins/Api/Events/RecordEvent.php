<?php

declare(strict_types=1);

namespace App\Plugins\Api\Events;

use App\Models\DynamicRecord;

/**
 * Basis der Ereignisse zu Datensätzen von Tabellen des Table-Designers und beschreibbaren angebundenen Tabellen.
 *
 * @api
 */
abstract readonly class RecordEvent
{
    public function __construct(
        /** Interner Name der Tabelle, z. B. „lager_bestaende“. */
        public string $table,
        public DynamicRecord $record,
        /** Beim Speichern: wird der Datensatz neu angelegt? (Beim Löschen immer false.) */
        protected bool $creating = false,
    ) {}

    /** Wert eines Felds nach der Änderung. */
    public function value(string $field): mixed
    {
        return $this->record->getAttribute($field);
    }

    /** Wert eines Felds vor der Änderung (beim Anlegen null). */
    public function previous(string $field): mixed
    {
        return $this->record->getOriginal($field);
    }
}
