<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Tables\Table;

/**
 * Listen der Administration: Schaltflächen wie „Erstellen“ oder „Importieren“ in der Kopfzeile der Tabelle (in einer
 * Kachel mit ihr, wie bei den Datentabellen) statt oben rechts im Seitenkopf.
 */
trait HeaderActionsInTable
{
    public function table(Table $table): Table
    {
        return $table->headerActions([...$this->getHeaderActions(), ...$table->getHeaderActions()]);
    }

    /** Im Seitenkopf nichts mehr (die Aktionen stehen in der Tabelle). */
    public function getCachedHeaderActions(): array
    {
        return [];
    }
}
