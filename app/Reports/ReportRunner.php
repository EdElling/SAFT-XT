<?php

declare(strict_types=1);

namespace App\Reports;

use App\Apps\AppInterfaces;
use App\DynamicData\DataAccess;
use App\DynamicData\DynamicDataNamespace;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\User;
use App\Reports\Dialects\QueryDialectResolver;
use Illuminate\Database\QueryException;

/**
 * Führt Auswertungen aus – immer mit den Datenrechten der aufrufenden Person: Sie braucht das
 * Leserecht auf jede beteiligte Tabelle, auch auf Tabellen, die nur im Filter vorkommen.
 * Wer eine Auswertung geteilt hat, spielt dafür keine Rolle.
 */
final class ReportRunner
{
    public function __construct(
        private readonly DefinitionResolver $resolver,
        private readonly ReportQuery $query,
        private readonly DynamicDataNamespace $namespace,
        private readonly QueryDialectResolver $dialects,
    ) {}

    /**
     * @param  int|null  $limit  z. B. für die Vorschau; nie mehr als reports.max_rows
     */
    public function run(Report|ReportDefinition $report, ?User $user, ?int $limit = null): ReportResult
    {
        $resolved = $this->resolve($report, $user);
        $max = (int) config('reports.max_rows', 5000);
        $limit = max(1, min($limit ?? $resolved->definition->limit ?? $max, $resolved->definition->limit ?? $max, $max));

        $connection = $this->namespace->connection();
        $dialect = $this->dialects->for($connection);

        try {
            $records = $dialect->withTimeout(
                $connection,
                (int) config('reports.timeout', 30),
                fn () => $this->query->build($resolved, $dialect)->limit($limit + 1)->get()->all(),
            );
        } catch (QueryException $e) {
            if ($dialect->isTimeout($e)) {
                throw new BusinessRuleException(__('reports.errors.timeout', ['seconds' => config('reports.timeout', 30)]), 'report.timeout');
            }

            throw $e;
        }

        return ReportResult::from($resolved, array_slice($records, 0, $limit), count($records) > $limit);
    }

    /** Prüft Definition und Rechte, ohne auszuführen. */
    public function resolve(Report|ReportDefinition $report, ?User $user): ResolvedReport
    {
        $resolved = $this->resolver->resolve($report instanceof Report ? $report->definitionObject() : $report);
        // Schnittstellen anderer Apps (B-107, Etappe 5b): freigegebene Felder ohne eigenes Leserecht, solange die App aktiv ist.
        $interfaces = array_filter($resolved->tables, fn (CustomTable $table): bool => in_array($table->id, $resolved->interfaces, true));
        $unavailable = array_filter($interfaces, fn (CustomTable $table): bool => AppInterfaces::unavailable($table, $user));

        if ($unavailable !== []) {
            throw new InvalidReportException([__('reports.errors.unavailable', [
                'tables' => implode(', ', array_map(fn (CustomTable $t): string => (string) $t->label, $unavailable)),
            ])], 'report.unavailable');
        }

        $denied = array_filter(array_diff_key($resolved->tables, $interfaces), fn (CustomTable $table): bool => ! DataAccess::allows($user, $table, 'view'));

        if ($denied !== []) {
            throw new InvalidReportException([__('reports.errors.forbidden', [
                'tables' => implode(', ', array_map(fn (CustomTable $t): string => (string) $t->label, $denied)),
            ])], 'report.forbidden');
        }

        return $resolved;
    }

    /** Darf die Person die Auswertung ausführen (Definition gültig, alle Tabellen lesbar)? */
    public function canRun(Report|ReportDefinition $report, ?User $user): bool
    {
        try {
            $this->resolve($report, $user);

            return true;
        } catch (InvalidReportException) {
            return false;
        }
    }
}
