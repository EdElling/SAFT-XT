<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use Closure;
use Filament\Actions\Action;

/**
 * Zusätzliche Aktion eines Plugins in der Datenansicht einer Tabelle (SaftPlugin::dataActions()): über der Tabelle
 * (neben „Neu“ und „Export“) oder je Zeile (neben „Details“). Die Aktion prüft ihre Sichtbarkeit selbst.
 *
 * @api
 */
final readonly class DataAction
{
    public const string TABLE = 'table';

    public const string RECORD = 'record';

    /**
     * @param  string  $table  interner Name der Tabelle
     * @param  self::TABLE|self::RECORD  $placement
     * @param  Closure(DataTable): Action  $make  baut die Filament-Aktion für die Tabelle; bei RECORD erhalten deren
     *                                            Closures den Datensatz als $record
     */
    public function __construct(
        public string $table,
        public string $placement,
        public Closure $make,
    ) {}
}
