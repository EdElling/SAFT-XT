<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Bestehende Felder auf „Nummernkreis“ umstellen (B-135, N2): vorhandene Werte analysieren (Vorschau, Änderungsplan)
 * und nach dem Typwechsel übernehmen. Ganzzahlen werden nach dem Muster formatiert, wenn es nur aus der laufenden
 * Nummer besteht; danach steht je Periode der höchste passende Wert im Zähler. Werte, die nicht passen, bleiben.
 *
 * Gelesen und geschrieben wird seitenweise über den Query Builder der Datenverbindung, ohne Raw-SQL. Das Formatieren
 * ist Teil der Strukturänderung (wie ein Spaltentyp, der sich ändert), nicht eine Änderung von Datensätzen: Es läuft
 * deshalb nicht über den RecordWriter, und das Protokoll des Felds hält den Typwechsel fest.
 */
final readonly class NumberConversion
{
    /** So viele nicht passende bzw. doppelte Werte zeigt die Vorschau. */
    public const int EXAMPLES = 10;

    private const int PAGE = 1000;

    public function __construct(
        private DynamicDataNamespace $namespace,
        private NumberSequences $sequences,
    ) {}

    /**
     * Typen, die sich auch mit Werten auf „Nummernkreis“ umstellen lassen.
     *
     * @return list<FieldType>
     */
    public static function sources(): array
    {
        return [FieldType::String, FieldType::Text, FieldType::Integer, FieldType::Select];
    }

    /**
     * Typen, zu denen ein Nummernkreis mit Werten zurück kann (die Werte bleiben, die Zähler entfallen).
     *
     * @return list<FieldType>
     */
    public static function targets(): array
    {
        return [FieldType::String, FieldType::Text];
    }

    /**
     * Vorhandene Werte einer Spalte unter einem Muster: wie viele passen, höchster Wert je Periode, nicht passende
     * und doppelte Werte (doppelt nur unter den nicht gelöschten Datensätzen, wie der eindeutige Index).
     */
    public function analyze(CustomTable $table, string $column, NumberPattern $pattern, FieldType $from): NumberAnalysis
    {
        $values = 0;
        $matching = 0;
        $empty = 0;
        $highest = [];
        $mismatches = [];
        $seen = [];
        $duplicates = [];

        foreach ($this->rows($table, $column) as $row) {
            $raw = $row->{$column};

            if ($raw === null || $raw === '') {
                $empty += (int) ($row->deleted_at === null);

                continue;
            }

            $value = $this->converted((string) $raw, $pattern, $from);
            $values++;
            $parsed = $pattern->parse($value);

            if ($parsed === null) {
                if (count($mismatches) < self::EXAMPLES) {
                    $mismatches[] = $value;
                }
            } else {
                $matching++;
                $key = NumberAnalysis::key($parsed['key'], $parsed['group']);
                $highest[$key] = max($highest[$key] ?? 0, $parsed['number']);
            }

            if ($row->deleted_at === null) {
                if (isset($seen[$value]) && count($duplicates) < self::EXAMPLES && ! in_array($value, $duplicates, true)) {
                    $duplicates[] = $value;
                }

                $seen[$value] = true;
            }
        }

        ksort($highest, SORT_STRING);

        return new NumberAnalysis($values, $matching, $empty, $highest, $mismatches, $duplicates);
    }

    /**
     * Nach dem Typwechsel (Spalte ist schon Text): Ganzzahlen formatieren und die Zähler je Periode (und Gruppe, N4)
     * auf den höchsten passenden Wert anheben. Läuft im Lock des DynamicSchemaService.
     */
    public function apply(CustomTable $table, CustomField $field, FieldType $from): NumberAnalysis
    {
        $pattern = NumberPattern::fromOptions($field->optionsArray());
        $column = $field->name;

        if ($from === FieldType::Integer && $pattern->isCounterOnly()) {
            $query = fn () => $this->namespace->connection()->table($this->namespace->qualify($table->name));

            foreach ($this->rows($table, $column) as $row) {
                $raw = $row->{$column};

                if ($raw !== null && $raw !== '' && ($value = $this->converted((string) $raw, $pattern, $from)) !== (string) $raw) {
                    $query()->where('id', $row->id)->update([$column => $value]);
                }
            }
        }

        $analysis = $this->analyze($table, $column, $pattern, FieldType::Number);
        $this->sequences->ensureTable();

        foreach ($analysis->highest as $key => $number) {
            ['group' => $group, 'period' => $period] = NumberAnalysis::split((string) $key);
            $this->sequences->raise((int) $field->id, $period, $number, $pattern->start, $group);
        }

        return $analysis;
    }

    /** Wert, wie er nach dem Umstellen in der Spalte steht (Ganzzahlen bei einem Muster nur aus der laufenden Nummer formatiert). */
    private function converted(string $value, NumberPattern $pattern, FieldType $from): string
    {
        if ($from === FieldType::Integer && $pattern->isCounterOnly() && preg_match('/^\d{1,18}$/', trim($value)) === 1) {
            return $pattern->format((int) trim($value), 0, 1);
        }

        return $value;
    }

    /** @return iterable<object> id, Spalte, deleted_at – alle Datensätze, auch im Papierkorb */
    private function rows(CustomTable $table, string $column): iterable
    {
        return $this->namespace->connection()->table($this->namespace->qualify($table->name))
            ->select(['id', $column, 'deleted_at'])
            ->lazyById(self::PAGE);
    }
}
