<?php

declare(strict_types=1);

namespace App\DynamicData\History;

use App\Models\Activity;
use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\Builder;

/** Einträge im Änderungsprotokoll zu einem Datensatz (Verlauf, B-174; Rückgängig je Eintrag, B-173). */
final class RecordHistory
{
    /** So viele Einträge zeigt der Verlauf (HISTORY.md §4 Frage 11). */
    public const int LIMIT = 25;

    /** @return Builder<Activity> */
    public static function query(DynamicRecord $record): Builder
    {
        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            // Text- und UUID-Schlüssel angebundener Tabellen stehen in den Eigenschaften.
            ->when(
                is_int($key = $record->activityKey()) || ctype_digit((string) $key),
                fn (Builder $q) => $q->where('subject_id', $key),
                fn (Builder $q) => $q->whereNull('subject_id')->where('properties->key', (string) $key),
            );
    }
}
