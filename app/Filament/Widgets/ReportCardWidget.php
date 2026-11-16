<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Report;
use App\Models\User;
use App\Reports\ReportPresenter;
use Filament\Widgets\Widget;

/**
 * Eine persönlich angeheftete Auswertung als eigene Dashboard-Karte (Diagramm bzw. Kennzahl vollständig,
 * Tabellen mit den ersten Zeilen). Das Dashboard legt je angehefteter Auswertung eine Karte an
 * (ReportCardWidget::make(['reportId' => …])); ausgeführt mit den eigenen Datenrechten.
 */
class ReportCardWidget extends Widget
{
    use RequiresPermissions;

    /** Zeilen einer Tabelle in der Karte; der Rest über „Alle anzeigen“. */
    public const int ROWS = 10;

    /** Nicht automatisch: Das Dashboard erzeugt die Karten je angehefteter Auswertung. */
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 60;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.report-card';

    public ?int $reportId = null;

    protected static function requiredPermissions(): array
    {
        return ['widgets.reports', 'reports.view'];
    }

    /** Standard: Diagramme und Kennzahlen halb breit, Tabellen und Kreuztabellen in voller Breite. */
    public static function prefersFullWidth(Report $report): bool
    {
        return in_array((string) ($report->definition['visual']['type'] ?? 'table'), ['table', 'crosstab'], true);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $report = $user->dashboardReports()->whereKey($this->reportId)->first();

        if (! $report instanceof Report || ! $user->can('view', $report)) {
            return ['item' => null];
        }

        return ['item' => [
            'report' => $report,
            'url' => ReportResource::getUrl('view', ['record' => $report]),
            ...app(ReportPresenter::class)->present($report, $user, self::ROWS),
        ]];
    }
}
