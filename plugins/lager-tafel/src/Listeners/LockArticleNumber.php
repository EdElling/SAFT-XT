<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Listeners;

use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use Saft\LagerTafel\ArticleNumber;

/**
 * Listener (RecordSaving, Tabelle Artikel): Sobald es zu einem Artikel Lagerbewegungen gibt, steht seine Nummer auf
 * Etiketten und Belegen – sie lässt sich dann nicht mehr ändern. Eine andere Schreibweise derselben Nummer
 * („a-1001“ statt „A-1001“) gilt nicht als Änderung. Gilt in der Datenansicht, beim Import und über die Schnittstelle.
 */
final class LockArticleNumber
{
    private const string MOVEMENTS = 'lager_bewegungen';

    public function __invoke(RecordSaving $event): void
    {
        if ($event->creating() || ! Saft::hasTable(self::MOVEMENTS)) {
            return;
        }

        $before = ArticleNumber::normalize((string) $event->previous('artikelnummer'));
        $after = ArticleNumber::normalize((string) $event->value('artikelnummer'));

        if ($before === $after) {
            return;
        }

        // Ohne Rechteprüfung lesen: Die Regel gilt auch für Personen, die Lagerbewegungen nicht sehen dürfen.
        $used = Saft::table(self::MOVEMENTS)->withoutAuthorization()->query()
            ->where('artikel_id', $event->record->getKey())
            ->exists();

        if ($used) {
            throw new RecordRejected(__('lager-tafel::tafel.errors.number_locked', ['number' => $before]));
        }
    }
}
