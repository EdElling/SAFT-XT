<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use Closure;
use Filament\Actions\Action;

/**
 * Zusätzliche Aktion eines Plugins in der Datenansicht einer Tabelle (SaftPlugin::dataActions()): über der Tabelle
 * (neben „Neu“ und „Export“), je Zeile (neben „Details“) oder als Sammelaktion für die ausgewählten Datensätze (neben
 * „Ändern …“, seit API 1.11). Die Aktion prüft ihre Sichtbarkeit selbst; alle erscheinen auch in Detailtabellen.
 *
 * Sammelaktionen (BULK) sind eine Filament\Actions\BulkAction; ihre Closures erhalten die ausgewählten Datensätze als
 * $records (geschnitten mit dem Filter, nie gelöschte). SAFT blendet sie im Papierkorb-Filter aus, prüft die Obergrenze
 * APP_DATA_BULK_MAX vor der Ausführung und hebt die Auswahl danach auf. Kennung, Kopf und „Rückgängig“ gibt es für sie
 * nicht; was mehr als einige hundert Datensätze verarbeitet, startet besser eine eigene Arbeit (Saft::startWork()).
 *
 * @api
 */
final readonly class DataAction
{
    public const string TABLE = 'table';

    public const string RECORD = 'record';

    /** Sammelaktion für die ausgewählten Datensätze (seit API 1.11). */
    public const string BULK = 'bulk';

    /**
     * @param  string  $table  interner Name der Tabelle
     * @param  self::TABLE|self::RECORD|self::BULK  $placement
     * @param  Closure(DataTable): Action  $make  baut die Filament-Aktion für die Tabelle; bei RECORD erhalten deren
     *                                            Closures den Datensatz als $record, bei BULK muss es eine BulkAction
     *                                            sein (Datensätze als $records)
     */
    public function __construct(
        public string $table,
        public string $placement,
        public Closure $make,
    ) {}
}
