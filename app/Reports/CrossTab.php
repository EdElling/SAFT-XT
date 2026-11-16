<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Kreuztabelle aus einem gruppierten Ergebnis: Zeilen = Gruppenfelder, Spalten = Werte des
 * Spaltenfeldes (sortiert, höchstens reports.max_crosstab_columns), Zellen = Werte (measures).
 */
final readonly class CrossTab
{
    /**
     * @param  list<ReportColumn>  $rowColumns  Gruppenfelder
     * @param  list<mixed>  $columnValues  Werte des Spaltenfeldes
     * @param  list<ReportColumn>  $measures
     * @param  list<array{keys: list<mixed>, cells: array<string, array<string, mixed>>}>  $rows  cells[Spaltenwert][Wert-Schlüssel]
     */
    public function __construct(
        public array $rowColumns,
        public ReportColumn $column,
        public array $columnValues,
        public array $measures,
        public array $rows,
        public bool $truncatedColumns,
    ) {}

    public static function from(ReportResult $result): self
    {
        $definition = $result->report->definition;
        $rowColumns = array_values(array_map(fn (string $key): ReportColumn => $result->column($key), $definition->groups));
        $column = $result->column($definition->columns[0]);
        $measures = array_values(array_filter($result->columns, fn (ReportColumn $c): bool => $c->role === ReportColumn::MEASURE));

        $values = [];

        foreach ($result->rows as $row) {
            $values[self::id($row[$column->key])] = $row[$column->key];
        }

        uasort($values, fn (mixed $a, mixed $b): int => [$a === null, $a] <=> [$b === null, $b]);
        $max = (int) config('reports.max_crosstab_columns', 50);
        $truncated = count($values) > $max;
        $values = array_slice($values, 0, $max, true);

        $rows = [];

        foreach ($result->rows as $row) {
            $keys = array_map(fn (ReportColumn $c): mixed => $row[$c->key], $rowColumns);
            $rowId = json_encode($keys);
            $columnId = self::id($row[$column->key]);

            if (! array_key_exists($columnId, $values)) {
                continue;
            }

            $rows[$rowId] ??= ['keys' => $keys, 'cells' => []];

            foreach ($measures as $measure) {
                $rows[$rowId]['cells'][$columnId][$measure->key] = $row[$measure->key];
            }
        }

        return new self($rowColumns, $column, array_values($values), $measures, array_values($rows), $truncated);
    }

    /** Schlüssel eines Spaltenwerts in cells. */
    public static function id(mixed $value): string
    {
        return $value === null ? "\0null" : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    public function cell(array $row, mixed $columnValue, string $measure): mixed
    {
        return $row['cells'][self::id($columnValue)][$measure] ?? null;
    }
}
