<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Daten für Chart.js aus einem gruppierten Ergebnis.
 *
 * X-Achse: Gruppenfelder (mehrere werden mit „ · “ verbunden). Reihen: bei einer Kreuztabelle
 * die Werte des Spaltenfeldes (erster Wert), sonst je Wert eine Reihe. Kreisdiagramm: eine
 * Reihe, je Segment eine Farbe.
 */
final class ChartData
{
    /** Kategorische Palette, in hell und dunkel gut unterscheidbar. */
    public const array PALETTE = ['#4f46e5', '#0891b2', '#d97706', '#16a34a', '#db2777', '#7c3aed', '#dc2626', '#0d9488', '#ca8a04', '#64748b'];

    public function __construct(private readonly ValueFormatter $formatter) {}

    /** @return array{labels: list<string>, datasets: list<array<string, mixed>>} */
    public function build(ReportResult $result, string $type): array
    {
        $groups = array_values(array_filter($result->columns, fn (ReportColumn $c): bool => $c->role === ReportColumn::GROUP && in_array($c->key, $result->report->definition->groups, true)));
        $measures = array_values(array_filter($result->columns, fn (ReportColumn $c): bool => $c->role === ReportColumn::MEASURE));
        $label = fn (array $values): string => implode(' · ', array_map(fn (ReportColumn $c, mixed $v): string => $this->formatter->format($v, $c), $groups, $values));

        $cross = $result->crossTab();

        if ($cross !== null) {
            $measure = $measures[0];
            $labels = array_map(fn (array $row): string => $label($row['keys']), $cross->rows);
            $datasets = array_map(fn (mixed $value, int $i): array => $this->dataset(
                $this->formatter->format($value, $cross->column) ?: __('reports.ui.empty_value'),
                array_map(fn (array $row): float|int|null => $this->number($cross->cell($row, $value, $measure->key)), $cross->rows),
                $i,
                $type,
            ), $cross->columnValues, array_keys($cross->columnValues));

            return ['labels' => $labels, 'datasets' => $datasets];
        }

        $labels = array_map(fn (array $row): string => $label(array_map(fn (ReportColumn $c): mixed => $row[$c->key], $groups)), $result->rows);

        if ($type === 'pie') {
            $data = array_map(fn (array $row): float|int|null => $this->number($row[$measures[0]->key]), $result->rows);

            return ['labels' => $labels, 'datasets' => [[
                'label' => $measures[0]->label,
                'data' => $data,
                'backgroundColor' => array_map(fn (int $i): string => self::color($i), array_keys($data)),
            ]]];
        }

        return ['labels' => $labels, 'datasets' => array_map(fn (ReportColumn $m, int $i): array => $this->dataset(
            $m->label,
            array_map(fn (array $row): float|int|null => $this->number($row[$m->key]), $result->rows),
            $i,
            $type,
        ), $measures, array_keys($measures))];
    }

    /**
     * @param  list<float|int|null>  $data
     * @return array<string, mixed>
     */
    private function dataset(string $label, array $data, int $index, string $type): array
    {
        $color = self::color($index);

        return [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $type === 'line' ? $color.'33' : $color,
            'borderColor' => $color,
            'fill' => false,
        ];
    }

    public static function color(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    private function number(mixed $value): float|int|null
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? (float) $value : null);
    }
}
