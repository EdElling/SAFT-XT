<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\RecordRejected;

/**
 * Listener: Bestände werden nur über Lagerbewegungen geführt (StockLedger). Direktes Anlegen, Ändern oder Löschen in
 * der Tabelle Bestände – über die Datenansicht, den Import oder die Schnittstelle – wird abgelehnt.
 */
final class StockGuard
{
    public function __invoke(RecordEvent $event): void
    {
        if (! StockLedger::isWriting()) {
            throw new RecordRejected(__('lager-umbuchung::umbuchung.errors.stock_locked'));
        }
    }
}
