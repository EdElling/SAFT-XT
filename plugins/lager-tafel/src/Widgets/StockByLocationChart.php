<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Widgets;

use App\Plugins\Api\DashboardWidget;
use Filament\Widgets\ChartWidget;
use Saft\LagerTafel\Pages\Lagerspiegel;
use Saft\LagerTafel\Warehouse;

/**
 * A.3 – Dashboard-Kachel „Lager: Bestand je Lagerort“: Balkendiagramm mit der Summe aller Bestände je Lagerort.
 * Sichtbar mit dem Leserecht des Plugins und den Datenrechten auf die Lager-Tabellen, solange die Lager-Demo da ist.
 */
final class StockByLocationChart extends ChartWidget implements DashboardWidget
{
    protected static ?int $sort = 51;

    /** Halbe Breite (eine Spalte des Dashboards). */
    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '16rem';

    public static function dashboardLabel(): string
    {
        return __('lager-tafel::tafel.widget.label');
    }

    public static function canView(): bool
    {
        $warehouse = app(Warehouse::class);

        return Lagerspiegel::canAccess() && $warehouse->available() && $warehouse->canRead();
    }

    public function getHeading(): string
    {
        return __('lager-tafel::tafel.widget.label');
    }

    public function getDescription(): string
    {
        return __('lager-tafel::tafel.widget.description');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array{datasets: list<array{label: string, data: list<int>}>, labels: list<string>} */
    protected function getData(): array
    {
        $warehouse = app(Warehouse::class);
        $totals = [];

        foreach ($warehouse->matrix() as $stocks) {
            foreach ($stocks as $location => $quantity) {
                $totals[$location] = ($totals[$location] ?? 0) + $quantity;
            }
        }

        $locations = $warehouse->locations();

        return [
            'datasets' => [[
                'label' => __('lager-tafel::tafel.widget.quantity'),
                'data' => array_values(array_map(static fn (int $id): int => $totals[$id] ?? 0, array_keys($locations))),
            ]],
            'labels' => array_values($locations),
        ];
    }

    /** @return array<string, mixed> Chart.js-Optionen: keine Legende (nur eine Reihe), ganze Zahlen auf der Achse */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
