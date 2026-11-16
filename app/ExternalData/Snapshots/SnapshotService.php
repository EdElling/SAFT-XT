<?php

declare(strict_types=1);

namespace App\ExternalData\Snapshots;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\DynamicRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Datenstände angebundener Tabellen: unveränderliche Kopien in SAFTs eigener Datenbank
 * (app_data.ds_<tabelle>), alle Stände einer Tabelle in derselben Kopie, unterschieden durch _snapshot.
 *
 * Aktualisieren liest die Quelle in Blöcken; scheitert es, wird der neue Stand wieder entfernt und
 * der vorherige bleibt gültig. Neue Spalten der Quelle kommen dazu, entfernte bleiben für alte
 * Stände erhalten. Aufbewahrung je Tabelle: die letzten N Stände, optional höchstens X Tage.
 * Eine Sperre je Tabelle verhindert, dass zwei Aktualisierungen (von Hand und geplant) gleichzeitig laufen.
 */
final class SnapshotService
{
    public const string PREFIX = 'ds_';

    public const string SNAPSHOT_COLUMN = '_snapshot';

    public const string ROW_COLUMN = '_row';

    /** PostgreSQL und MySQL erlauben je Abfrage höchstens 65'535 Platzhalter. */
    private const int MAX_PLACEHOLDERS = 60_000;

    private const int READ_CHUNK = 1000;

    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    public static function tableName(CustomTable $table): string
    {
        return self::PREFIX.$table->name;
    }

    public function qualified(CustomTable $table): string
    {
        return $this->namespace->qualify(self::tableName($table));
    }

    /** Neuen Stand ziehen; gibt ihn zurück oder wirft eine verständliche Meldung. */
    /** Code der BusinessRuleException, wenn dieselbe Tabelle gerade aktualisiert wird. */
    public const string BUSY = 'snapshot.busy';

    public function refresh(CustomTable $table, ?User $user): DataSnapshot
    {
        $this->assertExternal($table);
        $lock = Cache::lock('snapshots:table:'.$table->id, 3 * 3600);

        if (! $lock->get()) {
            throw new BusinessRuleException(__('snapshots.errors.busy'), self::BUSY);
        }

        try {
            return $this->refreshLocked($table, $user);
        } finally {
            $lock->release();
        }
    }

    private function refreshLocked(CustomTable $table, ?User $user): DataSnapshot
    {
        @set_time_limit(600);

        $snapshot = DataSnapshot::create([
            'custom_table_id' => $table->id,
            'status' => DataSnapshot::RUNNING,
            'started_at' => now(),
            'created_by' => $user?->id,
        ]);

        try {
            $columns = $this->ensureTable($table);
            $rows = $this->copy($table, $snapshot, $columns);
            $snapshot->update(['status' => DataSnapshot::COMPLETE, 'row_count' => $rows, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $this->deleteRows($table, $snapshot->id);
            $message = $e instanceof BusinessRuleException ? $e->getMessage() : __('snapshots.errors.failed');
            $snapshot->update(['status' => DataSnapshot::FAILED, 'finished_at' => now(), 'message' => $message]);
            Log::warning('Datenstand fehlgeschlagen', ['table' => $table->name, 'error' => $e->getMessage()]);

            throw new BusinessRuleException($message, 'snapshot.failed', previous: $e);
        }

        activity('data')->performedOn($table)->causedBy($user)->event('snapshot_created')
            ->withChanges(['attributes' => ['stand' => $snapshot->id, 'zeilen' => $rows]])
            ->log('snapshot_created');

        $this->prune($table);

        return $snapshot;
    }

    /** Einen Stand löschen (Zeilen und Eintrag). */
    public function delete(DataSnapshot $snapshot, ?User $user = null): void
    {
        $table = $snapshot->customTable;
        $this->deleteRows($table, $snapshot->id);
        $snapshot->delete();

        activity('data')->performedOn($table)->causedBy($user)->event('snapshot_deleted')
            ->withChanges(['attributes' => ['stand' => $snapshot->id, 'zeilen' => $snapshot->row_count]])
            ->log('snapshot_deleted');
    }

    /** Alle Stände einer Tabelle löschen (die leere Kopie bleibt für den nächsten Stand). */
    public function deleteAll(CustomTable $table, ?User $user = null): void
    {
        foreach ($table->snapshots()->get() as $snapshot) {
            $this->delete($snapshot, $user);
        }
    }

    /** Beim Lösen der Anbindung: Kopie samt Ständen entfernen. */
    public function drop(CustomTable $table): void
    {
        $this->connection()->getSchemaBuilder()->dropIfExists($this->qualified($table));
        $table->snapshots()->delete();
    }

    /**
     * Aufbewahrung: die letzten N vollständigen Stände, optional nicht älter als X Tage; fehlgeschlagene weg.
     *
     * @return int Zahl der gelöschten vollständigen Stände
     */
    public function prune(CustomTable $table): int
    {
        $deleted = 0;
        $keep = max(1, (int) $table->snapshot_keep);
        $complete = $table->snapshots()->where('status', DataSnapshot::COMPLETE)->orderByDesc('finished_at')->orderByDesc('id')->get();
        $cutoff = $table->snapshot_max_days !== null ? now()->subDays((int) $table->snapshot_max_days) : null;

        foreach ($complete->values() as $i => $snapshot) {
            // Der neueste Stand bleibt immer, auch wenn er älter als die Frist ist.
            if ($i > 0 && ($i >= $keep || ($cutoff !== null && $snapshot->finished_at?->lt($cutoff)))) {
                $this->delete($snapshot);
                $deleted++;
            }
        }

        foreach ($table->snapshots()->where('status', DataSnapshot::FAILED)->get() as $failed) {
            $failed->delete();
        }

        return $deleted;
    }

    /**
     * Stände, die seit über $hours Stunden auf „läuft“ stehen (abgebrochener Prozess): Zeilen entfernen und als
     * fehlgeschlagen markieren. Läuft gerade eine Aktualisierung derselben Tabelle, bleibt sie unberührt.
     *
     * @return int Zahl der aufgeräumten Stände
     */
    public function failHanging(int $hours): int
    {
        $count = 0;
        $hanging = DataSnapshot::query()->where('status', DataSnapshot::RUNNING)->where('started_at', '<', now()->subHours($hours))->with('customTable')->get();

        foreach ($hanging as $snapshot) {
            $table = $snapshot->customTable;
            $lock = Cache::lock('snapshots:table:'.$snapshot->custom_table_id, 3 * 3600);

            if ($table === null || ! $lock->get()) {
                continue;
            }

            try {
                $this->deleteRows($table, $snapshot->id);
                $snapshot->update(['status' => DataSnapshot::FAILED, 'finished_at' => now(), 'message' => __('snapshots.errors.aborted')]);
                $count++;
            } finally {
                $lock->release();
            }
        }

        return $count;
    }

    /**
     * Kopie anlegen bzw. um neue Spalten ergänzen.
     *
     * @return list<string> zu kopierende Spalten
     */
    private function ensureTable(CustomTable $table): array
    {
        $fields = $table->fields()->get()->keyBy('name');
        $columns = array_values(array_unique([...$table->keyColumns(), ...$fields->keys()->all()]));
        $schema = $this->connection()->getSchemaBuilder();
        $qualified = $this->qualified($table);

        $add = function (Blueprint $blueprint, string $column) use ($fields): void {
            $this->addColumn($blueprint, $column, $fields->get($column));
        };

        if (! $schema->hasTable($qualified)) {
            $schema->create($qualified, function (Blueprint $blueprint) use ($columns, $add): void {
                $blueprint->bigIncrements(self::ROW_COLUMN);
                $blueprint->unsignedBigInteger(self::SNAPSHOT_COLUMN)->index();

                foreach ($columns as $column) {
                    $add($blueprint, $column);
                }
            });

            return $columns;
        }

        $missing = array_diff($columns, $schema->getColumnListing($qualified));

        if ($missing !== []) {
            $schema->table($qualified, function (Blueprint $blueprint) use ($missing, $add): void {
                foreach ($missing as $column) {
                    $add($blueprint, $column);
                }
            });
        }

        return $columns;
    }

    /** Spaltentyp der Kopie: großzügig (Text statt begrenzter Länge), immer nullable. */
    private function addColumn(Blueprint $blueprint, string $column, ?CustomField $field): void
    {
        $type = $field === null ? FieldType::String : TableConnectorService::baseType($field);
        $options = $field?->optionsArray() ?? [];

        $definition = match ($type) {
            FieldType::Integer => $blueprint->bigInteger($column),
            FieldType::Decimal => $blueprint->decimal($column, (int) ($options['precision'] ?? 38), (int) ($options['scale'] ?? 10)),
            FieldType::Boolean => $blueprint->boolean($column),
            FieldType::Date => $blueprint->date($column),
            FieldType::DateTime => $blueprint->dateTime($column),
            default => $blueprint->text($column),
        };

        $definition->nullable();
    }

    /** @param  list<string>  $columns */
    private function copy(CustomTable $table, DataSnapshot $snapshot, array $columns): int
    {
        $max = (int) config('reports.snapshot_max_rows', 200_000);
        $source = DynamicRecord::forTable($table)->newQuery()->withoutGlobalScopes()->toBase()->select($columns);
        $keys = $table->keyColumns();

        // Seitenweise mit stabiler Reihenfolge (Schlüssel), ohne Schlüssel in einem Durchgang.
        $records = $keys === [] ? $source->cursor() : (function () use ($source, $keys) {
            foreach ($keys as $key) {
                $source->orderBy($key);
            }

            return $source->lazy(self::READ_CHUNK);
        })();

        $types = $this->types($table, $columns);
        $target = $this->connection()->table($this->qualified($table));
        $chunkSize = max(1, min(500, intdiv(self::MAX_PLACEHOLDERS, count($columns) + 1)));
        $chunk = [];
        $count = 0;

        foreach ($records as $record) {
            if (++$count > $max) {
                throw new BusinessRuleException(__('snapshots.errors.too_large', ['max' => number_format($max, 0, ',', '\'')]), 'snapshot.too_large');
            }

            $row = [self::SNAPSHOT_COLUMN => $snapshot->id];

            foreach ($columns as $column) {
                $row[$column] = $this->normalize(((array) $record)[$column] ?? null, $types[$column]);
            }

            $chunk[] = $row;

            if (count($chunk) >= $chunkSize) {
                (clone $target)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            (clone $target)->insert($chunk);
        }

        return $count;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, FieldType>
     */
    private function types(CustomTable $table, array $columns): array
    {
        $fields = $table->fields()->get()->keyBy('name');

        return collect($columns)->mapWithKeys(fn (string $column): array => [
            $column => $fields->has($column) ? TableConnectorService::baseType($fields->get($column)) : FieldType::String,
        ])->all();
    }

    /**
     * Werte über Datenbankgrenzen hinweg: Zeitpunkte mit Zeitzone (PostgreSQL „+01“) in die Zeitzone der
     * Anwendung, Wahrheitswerte als bool (MySQL liefert 0/1, PostgreSQL nimmt keine Zahlen an).
     */
    private function normalize(mixed $value, FieldType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            FieldType::Boolean => (bool) $value,
            FieldType::DateTime => CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'),
            FieldType::Date => substr((string) $value, 0, 10),
            FieldType::Integer => is_numeric($value) ? (int) $value : $value,
            FieldType::Decimal => is_numeric($value) ? (string) $value : $value,
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    private function deleteRows(CustomTable $table, int $snapshotId): void
    {
        try {
            if ($this->connection()->getSchemaBuilder()->hasTable($this->qualified($table))) {
                $this->connection()->table($this->qualified($table))->where(self::SNAPSHOT_COLUMN, $snapshotId)->delete();
            }
        } catch (Throwable $e) {
            Log::error('Datenstand: Zeilen konnten nicht entfernt werden', ['table' => $table->name, 'snapshot' => $snapshotId, 'error' => $e->getMessage()]);
        }
    }

    private function assertExternal(CustomTable $table): void
    {
        if (! $table->isExternal()) {
            throw new BusinessRuleException(__('snapshots.errors.not_external'), 'snapshot.not_external');
        }
    }

    private function connection(): Connection
    {
        return $this->namespace->connection();
    }
}
