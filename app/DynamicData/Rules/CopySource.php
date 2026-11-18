<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Vorgabe aus dem Master-Datensatz: Ein Feld übernimmt beim Erfassen den Wert eines Felds des über eine
 * Verknüpfung gewählten Datensatzes (z. B. Einzelpreis ← Artikel → Preis). Eine Kopie, keine Verbindung:
 * spätere Änderungen am Master wirken nicht zurück.
 */
final class CopySource
{
    /** Option am Feld: „verknüpfung.feld“, z. B. „artikel_id.preis“. */
    public const string OPTION = 'copy_from';

    /**
     * @return array{0: string, 1: string}|null Verknüpfung und Quellfeld
     */
    public static function parse(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^([a-z][a-z0-9_]*)\.([a-z][a-z0-9_]*)$/', $value, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function of(CustomField $field): ?array
    {
        return $field->type === FieldType::Relation ? null : self::parse($field->optionsArray()[self::OPTION] ?? null);
    }

    /** Darf ein Feld dieses Typs den Wert eines Felds jenes Typs übernehmen? */
    public static function compatible(FieldType $target, FieldType $source): bool
    {
        return match ($target) {
            FieldType::Integer, FieldType::Decimal => in_array($source, [FieldType::Integer, FieldType::Decimal], true),
            FieldType::Date, FieldType::DateTime => in_array($source, [FieldType::Date, FieldType::DateTime], true),
            FieldType::Boolean => $source === FieldType::Boolean,
            FieldType::String => in_array($source, [FieldType::String, FieldType::Select], true),
            FieldType::Text => in_array($source, [FieldType::String, FieldType::Select, FieldType::Text], true),
            FieldType::Select => $source === FieldType::Select,
            FieldType::Relation, FieldType::Computed, FieldType::File => false,
        };
    }

    /**
     * Mögliche Quellen für ein Feld dieses Typs: Felder der über Verknüpfungen der Tabelle erreichbaren Tabellen.
     *
     * @return array<string, string> „verknüpfung.feld“ => „Artikel → Preis“
     */
    public static function options(CustomTable $owner, FieldType $target): array
    {
        $options = [];

        foreach ($owner->fields()->get() as $relation) {
            if ($relation->type !== FieldType::Relation) {
                continue;
            }

            $source = CustomTable::query()->designer()->with('fields')->where('name', (string) ($relation->optionsArray()['table'] ?? ''))->first();

            foreach ($source?->fields ?? [] as $field) {
                if ($field->is_active && self::compatible($target, $field->valueType())) {
                    $options[$relation->name.'.'.$field->name] = $relation->label.' → '.$field->label;
                }
            }
        }

        return $options;
    }

    /** Wert des Quellfelds im gewählten Master-Datensatz; null ohne Auswahl oder ohne Datensatz. */
    public static function lookup(string $table, string $field, mixed $key): mixed
    {
        if ($key === null || $key === '') {
            return null;
        }

        try {
            return DynamicRecord::forTableName($table)->newQuery()->whereKey($key)->value($field);
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
