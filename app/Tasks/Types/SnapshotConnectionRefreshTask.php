<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DataSnapshot;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Datenstände aller angebundenen Tabellen einer Datenbankverbindung nacheinander aktualisieren. Scheitert eine
 * Tabelle, laufen die übrigen weiter; der Lauf gilt dann als fehlgeschlagen und nennt die Tabellen.
 */
final readonly class SnapshotConnectionRefreshTask implements TaskType
{
    public const string KEY = 'snapshot_connection_refresh';

    public function __construct(private SnapshotService $snapshots) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tasks.types.snapshot_connection_refresh');
    }

    public function creatable(): bool
    {
        return true;
    }

    public function parameterFields(): array
    {
        return [
            Select::make('connection_id')
                ->label(__('tasks.fields.connection'))
                ->options(fn (): array => DatabaseConnection::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
            Toggle::make('only_with_snapshot')
                ->label(__('tasks.fields.only_with_snapshot'))
                ->helperText(__('tasks.fields.only_with_snapshot_help'))
                ->default(true),
        ];
    }

    public function describe(array $parameters): string
    {
        $connection = $this->connection($parameters);

        if ($connection === null) {
            return __('tasks.missing_connection');
        }

        return $connection->name.' · '.($this->onlyWithSnapshot($parameters) ? __('tasks.only_with_snapshot_short') : __('tasks.all_tables_short'));
    }

    public function run(array $parameters): TaskResult
    {
        $connection = $this->connection($parameters) ?? throw new BusinessRuleException(__('tasks.missing_connection'));
        $tables = $this->tables($connection, $this->onlyWithSnapshot($parameters));
        $done = [];
        $skipped = [];
        $failed = [];
        $rows = 0;

        foreach ($tables as $table) {
            try {
                $rows += (int) $this->snapshots->refresh($table, null)->row_count;
                $done[] = $table->label;
            } catch (BusinessRuleException $e) {
                if ($e->errorCode === SnapshotService::BUSY) {
                    $skipped[] = $table->label;
                } else {
                    $failed[] = $table->label.': '.$e->getMessage();
                }
            } catch (Throwable $e) {
                Log::warning('Datenstand in Verbindungs-Aufgabe fehlgeschlagen', ['table' => $table->name, 'error' => $e->getMessage()]);
                $failed[] = $table->label.': '.__('snapshots.errors.failed');
            }
        }

        $message = __('tasks.results.snapshot_connection_refresh', ['done' => count($done), 'total' => $tables->count(), 'rows' => number_format($rows, 0, '.', '\'')])
            .($skipped === [] ? '' : ' '.__('tasks.results.skipped_tables', ['tables' => implode(', ', $skipped)]));

        if ($failed !== []) {
            throw new BusinessRuleException($message."\n".__('tasks.results.failed_tables').' '.implode('; ', $failed));
        }

        return new TaskResult($message, ['tables' => count($done), 'skipped' => count($skipped), 'rows' => $rows]);
    }

    /** @return Collection<int, CustomTable> */
    public function tables(DatabaseConnection $connection, bool $onlyWithSnapshot): Collection
    {
        return CustomTable::query()->external()
            ->where('database_connection_id', $connection->id)
            ->when($onlyWithSnapshot, fn ($query) => $query->whereHas('snapshots', fn ($q) => $q->where('status', DataSnapshot::COMPLETE)))
            ->orderBy('name')
            ->get();
    }

    /** @param  array<string, mixed>  $parameters */
    public function connection(array $parameters): ?DatabaseConnection
    {
        $id = $parameters['connection_id'] ?? null;

        return is_numeric($id) ? DatabaseConnection::query()->find((int) $id) : null;
    }

    /** @param  array<string, mixed>  $parameters */
    private function onlyWithSnapshot(array $parameters): bool
    {
        return (bool) ($parameters['only_with_snapshot'] ?? true);
    }
}
