<?php

declare(strict_types=1);

namespace App\Work\Types;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\DynamicData\Export\ExportFormat;
use App\DynamicData\Export\TableExporter;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Arbeit „Exportieren“ (Fundament 2, F3b): die gefilterte und sortierte Abfrage einer Liste blockweise in eine CSV-
 * oder Excel-Datei schreiben. Die Abfrage kommt serialisiert aus der Datenansicht (mit Filtern, Suche, Sortierung und
 * den Unterabfragen der Anzeigefelder) und wird in jedem Schritt an das Model der Tabelle gehängt. Cursor ist der
 * Versatz; die ID als letztes Sortierkriterium hält die Reihenfolge über die Schritte stabil. Tabellen ohne eindeutigen
 * Schlüssel werden in einem Durchgang gelesen (ein Schritt).
 *
 * Parameter: table_id, fields (Namen in Spaltenreihenfolge), format, title, query (serialisiert), rows (gezählt beim
 * Start), file (Dateiname), filter, search und selected (nur ausgewählte Datensätze, B-133 S4a; fürs Protokoll).
 */
final readonly class ExportWork implements WorkType
{
    public const string KEY = 'export';

    /** Zeilen je Block; der Schritt arbeitet Blöcke ab, bis sein Zeitbudget knapp wird. */
    public static function chunk(): int
    {
        return max(1, (int) config('dynamic_data.export_chunk', 1000));
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.export');
    }

    public function describe(array $parameters): string
    {
        $format = ExportFormat::tryFrom((string) ($parameters['format'] ?? ''));

        return trim((string) ($parameters['title'] ?? '').($format === null ? '' : ' · '.$format->label()));
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['offset' => 0, 'row' => 0], isset($parameters['rows']) ? (int) $parameters['rows'] : null);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $table = CustomTable::query()->with('fields')->find((int) ($step->parameters['table_id'] ?? 0))
            ?? throw new BusinessRuleException(__('work.export.table_missing'));
        $format = ExportFormat::from((string) $step->parameters['format']);
        $fields = $this->fields($table, (array) ($step->parameters['fields'] ?? []));
        $query = $this->query($table, (string) $step->parameters['query']);
        $model = $query->getModel();
        $offset = (int) ($step->state['offset'] ?? 0);
        $row = (int) ($step->state['row'] ?? 0);
        $exporter = TableExporter::open($format, dirname($step->path('export')), $fields, (string) $step->parameters['title']);
        $written = 0;

        try {
            if ($row === 0) {
                $exporter->header();
                $row = 2;
            }

            // Ohne eindeutigen Schlüssel wären Blöcke per Versatz nicht stabil: dann in einem Durchgang.
            if ($model instanceof DynamicRecord && ! $model->hasUniqueKey()) {
                $written = $exporter->rows((clone $query)->cursor(), $row);

                return $this->finish($exporter, $step, $table, $format, $offset + $written, $written);
            }

            $query->orderBy($model->qualifyColumn($model->getKeyName()));
            $chunk = self::chunk();

            do {
                $block = (clone $query)->offset($offset + $written)->limit($chunk)->get();
                $count = $exporter->rows($block, $row + $written);
                $written += $count;

                if ($count < $chunk) {
                    return $this->finish($exporter, $step, $table, $format, $offset + $written, $written);
                }
            } while ($step->remaining() > 2);

            $exporter->release();

            return StepOutcome::continue(['offset' => $offset + $written, 'row' => $row + $written], $written);
        } catch (\Throwable $e) {
            $exporter->release();

            throw $e;
        }
    }

    private function finish(TableExporter $exporter, WorkStep $step, CustomTable $table, ExportFormat $format, int $rows, int $written): StepOutcome
    {
        $file = basename((string) ($step->parameters['file'] ?? 'export.'.$format->value));
        $exporter->close($file);

        activity('data')
            ->performedOn($table)
            ->event('exported')
            ->withChanges(['attributes' => [
                'format' => $format->value,
                'zeilen' => $rows,
                'filter' => (array) ($step->parameters['filter'] ?? []),
                'suche' => (string) ($step->parameters['search'] ?? ''),
                ...(($step->parameters['selected'] ?? false) ? ['auswahl' => true] : []),
            ]])
            ->log('exported');

        return StepOutcome::finished(trans_choice('work.export.done', $rows, ['count' => number_format($rows, 0, '.', '\'')]), ['file' => $file, 'rows' => $rows], $written);
    }

    /**
     * Felder in der Reihenfolge der Spalten; inzwischen entfernte Felder fallen weg.
     *
     * @param  list<string>  $names
     * @return list<CustomField>
     */
    private function fields(CustomTable $table, array $names): array
    {
        $byName = $table->fields->keyBy('name');

        return array_values(array_filter(array_map(static fn (string $name): ?CustomField => $byName->get($name), $names)));
    }

    /**
     * Die serialisierte Abfrage an das Model der Tabelle hängen: Der entpackte Grundbauplan trägt Filter, Suche,
     * Sortierung und die Unterabfragen der Anzeigefelder, das Model die Casts und die Definition der Tabelle. Die
     * globalen Scopes stecken schon im Bauplan (das Paket wendet sie beim Verpacken an).
     *
     * @return Builder<DynamicRecord>
     */
    private function query(CustomTable $table, string $serialized): Builder
    {
        $restored = EloquentSerializeFacade::unserialize($serialized);

        return DynamicRecord::forTable($table)->newQuery()->withoutGlobalScopes()->setQuery($restored->getQuery());
    }
}
