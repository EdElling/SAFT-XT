<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;

/**
 * Vorgabe beim Anlegen für Datums- und Zeitfelder: das heutige Datum bzw. Datum und Uhrzeit beim Öffnen des Dialogs
 * „Neuer Datensatz“ (Zeitzone der Anwendung, auf die Minute). Nur eine Vorbelegung im Formular – Import und
 * Schnittstelle setzen nichts.
 */
final class NowDefault
{
    /** Option am Feld. */
    public const string OPTION = 'prefill_now';

    public static function applies(CustomField $field): bool
    {
        return in_array($field->type, [FieldType::Date, FieldType::DateTime], true)
            && (bool) ($field->optionsArray()[self::OPTION] ?? false);
    }

    /** Wert für das Formular: „2026-11-16“ bzw. „2026-11-16 14:05:00“. */
    public static function value(CustomField $field): string
    {
        return $field->type === FieldType::Date
            ? now()->toDateString()
            : now()->startOfMinute()->format('Y-m-d H:i:s');
    }
}
