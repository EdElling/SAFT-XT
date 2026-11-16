<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use App\Models\User;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Saft;
use Filament\Notifications\Notification;

/**
 * Listener: Wird ein Bestand gesenkt und liegt der Gesamtbestand des Artikels (alle Lagerorte) danach unter seinem
 * Mindestbestand, erhält die handelnde Person eine Benachrichtigung (Glocke). Gilt für jede Änderung eines Bestands –
 * Datenansicht, Import, Schnittstelle; eine Umbuchung ändert den Gesamtbestand nicht und meldet daher nichts.
 */
final class MinimumStockAlert
{
    public function __invoke(RecordSaved $event): void
    {
        $user = auth()->user();

        if (! $user instanceof User || StockTransfer::$running || StockLedger::isAdjusting() || ! Saft::hasTable(StockTransfer::ARTICLES)) {
            return;
        }

        // Lesen ohne Rechteprüfung: die Meldung soll auch kommen, wenn die Person Artikel nicht sehen darf.
        $articleId = (int) $event->value('artikel_id');
        $article = Saft::table(StockTransfer::ARTICLES)->withoutAuthorization()->find($articleId);
        $minimum = $article?->getAttribute('mindestbestand');

        if ($minimum === null) {
            return;
        }

        $total = (int) Saft::table(StockTransfer::STOCKS)->withoutAuthorization()->query()->where('artikel_id', $articleId)->sum('menge');
        $before = $total - (int) $event->value('menge') + ($event->creating() ? 0 : (int) $event->previous('menge'));

        // Nur wenn der Bestand gesenkt wurde und danach unter dem Mindestbestand liegt (Erhöhungen melden nichts).
        if ($total >= (int) $minimum || $total >= $before) {
            return;
        }

        Saft::notify($user, Notification::make()
            ->warning()
            ->title(__('lager-umbuchung::umbuchung.minimum.title'))
            ->body(__('lager-umbuchung::umbuchung.minimum.body', [
                'article' => (string) $article->getAttribute('bezeichnung'),
                'count' => $total,
                'minimum' => (int) $minimum,
            ])));
    }
}
