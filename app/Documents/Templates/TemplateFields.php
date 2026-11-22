<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\DynamicData\DetailRelations;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use Illuminate\Support\Collection;

/**
 * Was eine Vorlage von einer Tabelle verwenden kann: Felder, Verknüpfungen (bis MAX_DEPTH Schritte weiter) und
 * Kind-Tabellen (Tabellen, deren Verknüpfungsfeld auf sie zeigt) – für Feldauswahl, Prüfung und Daten.
 */
final class TemplateFields
{
    /** Schritte über Verknüpfungen: {{kunde_id.firma_id.ort}} sind zwei. */
    public const int MAX_DEPTH = 3;

    /** Platzhalter auf oberster Ebene neben den Feldern. */
    public const string SENDER = 'absender';

    public const string TODAY = 'heute';

    /** @return Collection<int, CustomField> aktive Felder in der Reihenfolge der Tabelle */
    public static function fields(CustomTable $table): Collection
    {
        return once(static fn (): Collection => $table->fields()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->each(static fn (CustomField $field) => $field->setRelation('customTable', $table)));
    }

    public static function field(CustomTable $table, string $name): ?CustomField
    {
        return self::fields($table)->firstWhere('name', $name);
    }

    /** Zieltabelle eines Verknüpfungsfelds. */
    public static function target(CustomField $field): ?CustomTable
    {
        if ($field->valueType() !== FieldType::Relation) {
            return null;
        }

        $name = (string) ($field->optionsArray()['table'] ?? '');

        return once(static fn (): ?CustomTable => CustomTable::query()->where('name', $name)->first());
    }

    /**
     * Kind-Tabellen: Name des Abschnitts => Verknüpfungsfeld der Kind-Tabelle. Der Abschnitt heisst wie die Kind-Tabelle;
     * zeigen mehrere Felder derselben Tabelle hierher, heissen die weiteren tabelle__feld.
     *
     * @return array<string, CustomField>
     */
    public static function children(CustomTable $table): array
    {
        return once(static function () use ($table): array {
            $fields = DetailRelations::referencing($table->name)
                ->filter(static fn (CustomField $field): bool => $field->is_active && $field->customTable !== null && $field->customTable->is_active);
            $children = [];

            foreach ($fields as $field) {
                $name = $field->customTable->name;
                $children[array_key_exists($name, $children) ? $name.'__'.$field->name : $name] = $field;
            }

            return $children;
        });
    }

    /** Formatierungen (Filter), wie sie in der Vorlage stehen; Zahl: mit Stellen, z. B. Zahl:2. */
    public const array FILTERS = ['CHF', 'Zahl', 'Datum', 'DatumLang', 'Zeilen', 'Roh', 'Gross'];

    public static function isFilter(string $name): bool
    {
        return in_array($name, self::FILTERS, true) || preg_match('/^Zahl:\d$/', $name) === 1;
    }
}
