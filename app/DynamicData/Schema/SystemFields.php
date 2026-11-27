<?php

declare(strict_types=1);

namespace App\DynamicData\Schema;

use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Systemspalten einer Designer-Tabelle als Felder für Datenansicht und Export (B-159): angelegt/geändert am
 * (Datum/Uhrzeit) und angelegt/geändert von (Person, Benutzer-ID). Ungespeicherte Feldbeschreibungen ohne ID, damit
 * Spalten, Filter und Export denselben Weg gehen wie bei Feldern des Table-Designers. Angebundene Tabellen haben diese
 * Spalten nicht. In Auswertungen gibt es sie (noch) nicht: dort verweisen Felder über ihre ID.
 */
final class SystemFields
{
    /** @var list<string> */
    public const array DATES = ['created_at', 'updated_at'];

    /** @var list<string> */
    public const array PERSONS = ['created_by', 'updated_by'];

    /** @return list<CustomField> in der Reihenfolge angelegt am, geändert am, angelegt von, geändert von */
    public static function for(CustomTable $table): array
    {
        if ($table->isExternal()) {
            return [];
        }

        return array_map(static function (string $name): CustomField {
            $field = new CustomField;
            $field->forceFill([
                'name' => $name,
                'type' => in_array($name, self::DATES, true) ? FieldType::DateTime : FieldType::String,
                'is_active' => true,
                'is_required' => false,
                'is_searchable' => false,
                'show_in_table' => false,
                'options' => [],
            ]);
            $field->label = __('data.system.'.$name);

            return $field;
        }, [...self::DATES, ...self::PERSONS]);
    }

    /** Eine Systemspalte (ungespeichert, mit einem der vier Namen)? */
    public static function is(CustomField $field): bool
    {
        return ! $field->exists && in_array($field->name, [...self::DATES, ...self::PERSONS], true);
    }

    /** Angelegt bzw. geändert von – der Wert ist eine Benutzer-ID, angezeigt wird der Name. */
    public static function isPerson(CustomField $field): bool
    {
        return ! $field->exists && in_array($field->name, self::PERSONS, true);
    }
}
