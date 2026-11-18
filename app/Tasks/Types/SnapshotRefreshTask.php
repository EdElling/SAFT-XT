<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use Filament\Forms\Components\Select;

/** Neuen Datenstand einer angebundenen Tabelle ziehen (Table-Connector › Datenstände). */
final readonly class SnapshotRefreshTask implements TaskType
{
    public const string KEY = 'snapshot_refresh';

    public function __construct(private SnapshotService $snapshots) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tasks.types.snapshot_refresh');
    }

    public function creatable(): bool
    {
        return true;
    }

    public function parameterFields(): array
    {
        return [
            Select::make('table_id')
                ->label(__('tasks.fields.table'))
                // Nach App gruppiert (B-107); die Aufgabe gehört danach zur App der Tabelle.
                ->options(fn (): array => CustomTable::query()->external()->with(['databaseConnection', 'app'])->get()
                    ->groupBy(fn (CustomTable $table): string => (string) ($table->app?->name ?? __('apps.system')))
                    ->sortKeys()
                    ->map(fn ($tables): array => $tables->mapWithKeys(fn (CustomTable $table): array => [$table->id => $this->tableLabel($table)])->sort()->all())
                    ->all())
                ->searchable()
                ->required(),
        ];
    }

    public function describe(array $parameters): string
    {
        $table = $this->table($parameters);

        return $table === null ? __('tasks.missing_table') : $this->tableLabel($table);
    }

    public function run(array $parameters): TaskResult
    {
        $table = $this->table($parameters) ?? throw new BusinessRuleException(__('tasks.missing_table'));

        try {
            $snapshot = $this->snapshots->refresh($table, null);
        } catch (BusinessRuleException $e) {
            if ($e->errorCode === SnapshotService::BUSY) {
                throw new TaskSkipped($e->getMessage(), previous: $e);
            }

            throw $e;
        }

        return new TaskResult(
            __('tasks.results.snapshot_refresh', ['rows' => number_format((int) $snapshot->row_count, 0, '.', '\'')]),
            ['snapshot_id' => $snapshot->id, 'rows' => (int) $snapshot->row_count],
        );
    }

    /** @param  array<string, mixed>  $parameters */
    public function table(array $parameters): ?CustomTable
    {
        $id = $parameters['table_id'] ?? null;

        return is_numeric($id) ? CustomTable::query()->external()->find((int) $id) : null;
    }

    private function tableLabel(CustomTable $table): string
    {
        return $table->label.' ('.($table->databaseConnection?->name ?? '?').')';
    }
}
