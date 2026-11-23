<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use App\Models\DynamicRecord;

/**
 * Stand eines Datensatzes für die optimistische Sperre (Fundament 2, F2): Prüfsumme über die Rohwerte aller
 * bearbeitbaren Felder – ohne berechnete Felder und Systemspalten. So löst eine nachgeführte Summe im Master-Datensatz
 * keinen Fehlalarm aus, und angebundene Tabellen ohne Zeitstempel sind gleich geschützt. Das Formular trägt die
 * Version mit; der Writer vergleicht sie beim Speichern mit dem gesperrten aktuellen Stand.
 */
final class RecordVersion
{
    public static function of(DynamicRecord $record): string
    {
        $raw = $record->getRawOriginal() ?: $record->getAttributes();
        $values = [];

        foreach (self::fields($record) as $field) {
            $values[$field] = $raw[$field] ?? null;
        }

        return sha1((string) json_encode($values, JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return list<string> Felder, die in die Version eingehen (bearbeitbar, keine berechneten) */
    public static function fields(DynamicRecord $record): array
    {
        $definition = $record->definition();
        $computed = array_keys($definition->computed ?? []);
        $fields = array_values(array_diff($definition->fillable ?? $record->getFillable(), $computed));
        sort($fields);

        return $fields;
    }
}
