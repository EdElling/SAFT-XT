<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\UserNames;
use App\ExternalData\TableConnectorService;

/**
 * Ergebnis einer Auswertung: Spalten und Zeilen mit einheitlichen PHP-Werten (Zahlen als
 * int/float, Ja/Nein als bool, sonst Text) – unabhängig davon, was die Datenbank liefert.
 */
final readonly class ReportResult
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public ResolvedReport $report,
        public array $columns,
        public array $rows,
        public bool $truncated,
    ) {}

    /** @param  iterable<object|array<string, mixed>>  $records */
    public static function from(ResolvedReport $report, iterable $records, bool $truncated): self
    {
        $columns = self::columnsOf($report);
        $rows = [];
        $displays = [];

        foreach ($records as $record) {
            $record = (array) $record;
            $row = [];

            foreach ($columns as $column) {
                $row[$column->key] = self::normalize($record[$column->key] ?? null, $column);

                // Verknüpfungsfeld: Name aus den Anzeigefeldern; der Wert bleibt der Schlüssel (Gruppen, Kreuztabelle).
                if ($column->aggregate === null && $column->field?->display !== null && $row[$column->key] !== null) {
                    $displays[$column->key][(string) $row[$column->key]] = RelationDisplay::compose(
                        array_map(fn (string $alias): mixed => $record[$alias] ?? null, $column->field->displayKeys()),
                        $column->field->display['separator'],
                    ) ?? (string) $row[$column->key];
                }

                // Angelegt/geändert von (B-183): Name zur Benutzer-ID. Die Benutzer liegen auf der Hauptverbindung,
                // deshalb nach der Abfrage und nicht als Verknüpfung (einmal je Anfrage, UserNames).
                if ($column->aggregate === null && $column->field?->isPerson() && $row[$column->key] !== null) {
                    $displays[$column->key][(string) $row[$column->key]] ??= (string) app(UserNames::class)->name($row[$column->key]);
                }
            }

            $rows[] = $row;
        }

        $columns = array_map(
            fn (ReportColumn $column): ReportColumn => $column->aggregate === null && ($column->field?->display !== null || $column->field?->isPerson())
                ? $column->withDisplay($displays[$column->key] ?? [])
                : $column,
            $columns,
        );

        return new self($report, $columns, $truncated ? $rows : self::sortByPersonNames($report, $rows, $displays), $truncated);
    }

    /**
     * Gruppen nach Person: Die Datenbank sortiert nach der Benutzer-ID; ein vollständiges Ergebnis wird hier nach dem
     * Namen geordnet. Je Sortierstufe mit einer Person werden die Zeilen, die in den Stufen davor übereinstimmen, stabil
     * nach dem Namen sortiert – die Reihenfolge der übrigen Stufen bleibt die der Datenbank.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, string>>  $names
     * @return list<array<string, mixed>>
     */
    private static function sortByPersonNames(ResolvedReport $report, array $rows, array $names): array
    {
        $definition = $report->definition;

        if (! $definition->isGrouped()) {
            return $rows;
        }

        $sort = $definition->sort !== []
            ? $definition->sort
            : array_map(static fn (string $key): array => ['key' => $key, 'dir' => 'asc'], [...$definition->groups, ...$definition->columns]);
        $before = [];

        foreach ($sort as $level) {
            $key = $level['key'];

            if (($report->fields[$key] ?? null)?->isPerson() && ! in_array($key, $before, true)) {
                $name = static fn (array $row): string => mb_strtolower($names[$key][(string) ($row[$key] ?? '')] ?? '');
                $compare = static fn (array $a, array $b): int => $level['dir'] === 'desc' ? $name($b) <=> $name($a) : $name($a) <=> $name($b);
                $sorted = [];
                $run = [];

                foreach ($rows as $row) {
                    if ($run !== [] && array_intersect_key($row, array_flip($before)) != array_intersect_key($run[0], array_flip($before))) {
                        usort($run, $compare);
                        $sorted = [...$sorted, ...$run];
                        $run = [];
                    }

                    $run[] = $row;
                }

                usort($run, $compare);
                $rows = [...$sorted, ...$run];
            }

            $before[] = $key;
        }

        return $rows;
    }

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /** Kreuztabelle, wenn die Auswertung ein Spaltenfeld hat. */
    public function crossTab(): ?CrossTab
    {
        return $this->report->definition->columns === [] ? null : CrossTab::from($this);
    }

    /** @return list<ReportColumn> */
    private static function columnsOf(ResolvedReport $report): array
    {
        $definition = $report->definition;

        if (! $definition->isGrouped()) {
            return array_values(array_map(
                fn (string $key): ReportColumn => ($expression = $definition->expression($key)) !== null
                    ? new ReportColumn($key, self::expressionLabel($report, $expression['label'], $expression['expr']), ReportColumn::FIELD, scale: $expression['scale'], computed: true)
                    : new ReportColumn($key, $report->field($key)->label, ReportColumn::FIELD, $report->field($key)),
                $definition->shownKeys(),
            ));
        }

        $columns = array_map(
            fn (string $key): ReportColumn => new ReportColumn($key, $report->field($key)->label, ReportColumn::GROUP, $report->field($key)),
            [...$definition->groups, ...$definition->columns],
        );

        $labels = self::measureLabels($report);

        foreach ($definition->measures as $measure) {
            if (isset($measure['expr']) || $measure['agg'] === ReportDefinition::CALC) {
                $columns[] = new ReportColumn($measure['key'], $labels[$measure['key']], ReportColumn::MEASURE, aggregate: $measure['agg'], scale: (int) ($measure['scale'] ?? 2), computed: true);

                continue;
            }

            $field = $measure['field'] === null ? null : $report->field($measure['field']);
            $columns[] = new ReportColumn($measure['key'], $labels[$measure['key']], ReportColumn::MEASURE, $field, $measure['agg']);
        }

        return array_values($columns);
    }

    /**
     * Bezeichnung jedes Werts: eigene oder „Summe Betrag“, „Summe [Menge] × [Preis]“; eine Kennzahl aus Werten zeigt
     * ihre Formel mit den Bezeichnungen der übrigen Werte, z. B. „[Budget] ÷ [Anzahl]“.
     *
     * @return array<string, string>
     */
    public static function measureLabels(ResolvedReport $report): array
    {
        $labels = [];

        foreach ([...$report->definition->groups, ...$report->definition->columns] as $key) {
            $labels[$key] = $report->field($key)->label;
        }

        foreach ($report->definition->measures as $measure) {
            if ($measure['agg'] === ReportDefinition::CALC) {
                continue;
            }

            $field = $measure['field'] === null ? null : $report->field($measure['field']);
            $labels[$measure['key']] = $measure['label'] ?? match (true) {
                isset($measure['expr']) => __('reports.measure_label', ['agg' => __('reports.aggregates.'.$measure['agg']), 'field' => self::expressionLabel($report, null, (array) $measure['expr'])]),
                $field === null => __('reports.aggregates.count'),
                default => __('reports.measure_label', ['agg' => __('reports.aggregates.'.$measure['agg']), 'field' => $field->label]),
            };
        }

        foreach ($report->definition->measures as $measure) {
            if ($measure['agg'] === ReportDefinition::CALC) {
                $labels[$measure['key']] = $measure['label'] ?? ReportExpression::text((array) $measure['calc'], fn (string $key): string => $labels[$key] ?? $key);
            }
        }

        return $labels;
    }

    /**
     * Bezeichnung einer Formel: eigene oder die Formel selbst mit den Feldnamen.
     *
     * @param  array<string, mixed>  $expr
     */
    public static function expressionLabel(ResolvedReport $report, ?string $label, array $expr): string
    {
        return $label ?? ReportExpression::text($expr, fn (string $key): string => $report->field($key)->label);
    }

    private static function normalize(mixed $value, ReportColumn $column): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column->type()) {
            FieldType::Integer => is_numeric($value) ? (int) $value : $value,
            FieldType::Relation => is_numeric($value) && TableConnectorService::baseType($column->field->field) === FieldType::Integer ? (int) $value : $value,
            FieldType::Decimal => is_numeric($value) ? (float) $value : $value,
            FieldType::Boolean => (bool) $value,
            default => is_scalar($value) ? (string) $value : $value,
        };
    }
}
