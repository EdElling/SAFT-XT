<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Listeners;

use App\Plugins\Api\Events\RecordSaving;
use Saft\LagerTafel\ArticleNumber;

/**
 * Listener (RecordSaving, Tabelle Artikel): speichert die Artikelnummer immer in derselben Schreibweise – egal ob sie
 * in der Datenansicht, im Import oder über die Schnittstelle erfasst wurde.
 */
final class NormalizeArticleNumber
{
    public function __invoke(RecordSaving $event): void
    {
        $number = $event->value('artikelnummer');

        if (is_string($number) && $number !== '') {
            $event->record->setAttribute('artikelnummer', ArticleNumber::normalize($number));
        }
    }
}
