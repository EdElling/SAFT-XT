<?php

declare(strict_types=1);

namespace App\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Report;
use App\Models\User;

/**
 * Bereitet eine Auswertung für die Ansicht auf: Ergebnis oder verständliche Gründe, warum sie
 * nicht läuft (nie eine Fehlerseite), dazu die Diagrammdaten.
 */
final class ReportPresenter
{
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly ValueFormatter $formatter,
        private readonly ChartData $charts,
    ) {}

    /** @return array{result: ?ReportResult, problems: list<string>, visual: string, formatter: ValueFormatter, chart: ?array} */
    public function present(Report|ReportDefinition $report, ?User $user, ?int $limit = null): array
    {
        $definition = $report instanceof Report ? $report->definitionObject() : $report;
        $visual = (string) ($definition->visual['type'] ?? 'table');
        $result = null;
        $problems = [];

        try {
            $result = $this->runner->run($definition, $user, $limit);
        } catch (InvalidReportException $e) {
            $problems = $e->errors;
        } catch (BusinessRuleException $e) {
            $problems = [$e->getMessage()];
        }

        $chart = $result !== null && $result->rows !== [] && in_array($visual, ['bar', 'line', 'pie'], true)
            ? $this->charts->build($result, $visual)
            : null;

        return ['result' => $result, 'problems' => $problems, 'visual' => $visual, 'formatter' => $this->formatter, 'chart' => $chart];
    }
}
