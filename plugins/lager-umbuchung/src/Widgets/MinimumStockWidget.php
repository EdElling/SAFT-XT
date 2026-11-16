<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung\Widgets;

use App\Models\DynamicRecord;
use App\Plugins\Api\DashboardWidget;
use App\Plugins\Api\Saft;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Saft\LagerUmbuchung\Pages\Umbuchung;
use Saft\LagerUmbuchung\StockTransfer;

/**
 * Dashboard-Karte: Artikel, deren Gesamtbestand (alle Lagerorte) unter dem Mindestbestand liegt. Sichtbar mit dem
 * Recht des Plugins und den Datenrechten auf Artikel und Bestände, solange die Lager-Demo vorhanden ist.
 */
final class MinimumStockWidget extends StatsOverviewWidget implements DashboardWidget
{
    private const string T = 'lager-umbuchung::umbuchung.widget.';

    /** Namen der Artikel in der Beschreibung, danach „und n weitere“. */
    private const int NAMES = 3;

    protected static ?int $sort = 50;

    /** Halbe Breite (eine Spalte des Dashboards). */
    protected int|string|array $columnSpan = 1;

    protected int|array|null $columns = 2;

    public static function dashboardLabel(): string
    {
        return __(self::T.'label');
    }

    public static function canView(): bool
    {
        return Umbuchung::canAccess()
            && app(StockTransfer::class)->available()
            && in_array('mindestbestand', Saft::table(StockTransfer::ARTICLES)->fieldNames(), true)
            && Saft::table(StockTransfer::ARTICLES)->can('view')
            && Saft::table(StockTransfer::STOCKS)->can('view');
    }

    protected function getHeading(): ?string
    {
        return __(self::T.'label');
    }

    protected function getStats(): array
    {
        $totals = [];

        foreach (Saft::table(StockTransfer::STOCKS)->query()->get(['artikel_id', 'menge']) as $stock) {
            $article = (int) $stock->getAttribute('artikel_id');
            $totals[$article] = ($totals[$article] ?? 0) + (int) $stock->getAttribute('menge');
        }

        $below = Saft::table(StockTransfer::ARTICLES)->query()->whereNotNull('mindestbestand')->orderBy('bezeichnung')->get()
            ->filter(static fn (DynamicRecord $a): bool => ($totals[(int) $a->getKey()] ?? 0) < (int) $a->getAttribute('mindestbestand'))
            ->map(static fn (DynamicRecord $a): string => __(self::T.'item', [
                'article' => (string) $a->getAttribute('bezeichnung'),
                'count' => $totals[(int) $a->getKey()] ?? 0,
                'minimum' => (int) $a->getAttribute('mindestbestand'),
            ]))
            ->values();

        $names = $below->take(self::NAMES)->implode(', ');

        return [
            Stat::make(__(self::T.'below'), (string) $below->count())
                ->description($below->isEmpty() ? __(self::T.'none') : ($below->count() > self::NAMES
                    ? __(self::T.'more', ['names' => $names, 'count' => $below->count() - self::NAMES])
                    : $names))
                ->color($below->isEmpty() ? 'success' : 'danger')
                ->icon($below->isEmpty() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'),
            Stat::make(__(self::T.'total'), number_format(array_sum($totals), 0, '.', '’'))
                ->url(Umbuchung::getUrl())
                ->description(__(self::T.'transfer')),
        ];
    }
}
