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
use App\Models\WorkItem;
use App\Work\Types\SnapshotRefreshWork;
use App\Work\WorkService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Datenstände angebundener Tabellen: unveränderliche Kopien in SAFTs eigener Datenbank
 * (app_data.ds_<tabelle>), alle Stände einer Tabelle in derselben Kopie, unterschieden durch _snapshot.
 *
 * Gezogen wird ein Stand als Arbeit (Fundament 2, F3b; App\Work\Types\SnapshotRefreshWork): start() legt den Stand
 * „läuft“ und die Arbeit an und arbeitet kurz mit, refresh() treibt sie bis zum Ende (Aufgaben, Konsole). Die Arbeit
 * liest die Quelle in Blöcken (copyBlock()) mit einem Cursor über die Schlüsselspalten; scheitert sie oder wird sie
 * abgebrochen, entfernt sie den neuen Stand wieder, und der vorherige bleibt gültig. Ein laufender Stand mit offener
 * Arbeit gilt als „beschäftigt“ – ein zweiter Lauf derselben Tabelle wird abgewiesen. Neue Spalten der Quelle kommen
 * dazu, entfernte bleiben für alte Stände erhalten. Aufbewahrung je Tabelle: die letzten N Stände, optional höchstens
 * X Tage.
 */
final class SnapshotService
{
    public const string PREFIX = 'ds_';

    public const string SNAPSHOT_COLUMN = '_snapshot';

    public const string ROW_COLUMN = '_row';

    /** Code der BusinessRuleException, wenn dieselbe Tabelle gerade aktualisiert wird. */
    public const string BUSY = 'snapshot.busy';

    /** PostgreSQL und MySQL erlauben je Abfrage höchstens 65'535 Platzhalter. */
    private const int MAX_PLACEHOLDERS = 60_000;

    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    public static function tableName(CustomTable $table): string
    {
        return self::PREFIX.$table->name;
    }

    public function qualified(CustomTable $table): string
    {
        return $this->namespace->qualify(self::tableName($table));
    }

    /**
     * Neuen Stand ziehen: Arbeit starten und bis zu $budget Sekunden im Request mitarbeiten (Vorgabe
     * work.inline_budget). Ist die Arbeit danach fertig, steht der Stand; sonst zieht der Scheduler ihn zu Ende.
     *
     * @throws BusinessRuleException keine angebundene Tabelle oder gerade beschäftigt (BUSY)
     */
    public function start(CustomTable $table, ?User $user, ?float $budget = null): WorkItem
    {
        return $this->work()->advance($this->begin($table, $user), $budget);
    }

    /**
     * Neuen Stand ziehen und auf ihn warten (Aufgaben, Konsole, Tests).
     *
     * @throws BusinessRuleException beschäftigt (BUSY) oder fehlgeschlagen (snapshot.failed) mit der Meldung der Arbeit
     */
    public function refresh(CustomTable $table, ?User $user): DataSnapshot
    {
        $item = $this->work()->drive($this->begin($table, $user));
        $snapshot = DataSnapshot::query()->find((int) ($item->parameterValues()['snapshot_id'] ?? 0));

        if ($item->status !== WorkItem::DONE || $snapshot === null) {
            throw new BusinessRuleException((string) ($item->message ?: __('snapshots.errors.failed')), 'snapshot.failed');
        }

        return $snapshot;
    }

    /** Wird die Tabelle gerade aktualisiert (Stand „läuft“ mit offener Arbeit)? */
    public function busy(CustomTable $table): bool
    {
        return $table->snapshots()->where('status', DataSnapshot::RUNNING)
            ->whereHas('work', static fn ($query) => $query->whereIn('status', WorkItem::OPEN))
            ->exists();
    }

    /** Die offene Arbeit, die diese Tabelle gerade aktualisiert. */
    public function openWork(CustomTable $table): ?WorkItem
    {
        $snapshot = $table->snapshots()->where('status', DataSnapshot::RUNNING)
            ->whereHas('work', static fn ($query) => $query->whereIn('status', WorkItem::OPEN))
            ->with('work')->latest('id')->first();

        return $snapshot?->work;
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
     * Stände „läuft“, zu denen keine offene Arbeit mehr gehört (Arbeit weggeräumt, bevor sie aufräumen konnte): Zeilen
     * entfernen und als fehlgeschlagen markieren. Frisch gestartete bleiben unberührt.
     *
     * @return int Zahl der aufgeräumten Stände
     */
    public function failAbandoned(): int
    {
        $abandoned = DataSnapshot::query()->where('status', DataSnapshot::RUNNING)
            ->where('started_at', '<', now()->subMinutes(10))
            ->whereDoesntHave('work', static fn ($query) => $query->whereIn('status', WorkItem::OPEN))
            ->get();

        foreach ($abandoned as $snapshot) {
            $this->fail($snapshot, __('snapshots.errors.aborted'));
        }

        return $abandoned->count();
    }

    // --- Bausteine der Arbeit ---------------------------------------------------------------

    /**
     * Kopie anlegen bzw. um neue Spalten ergänzen (DDL – ausserhalb der Transaktion eines Schritts, in prepare()).
     *
     * @return list<string> zu kopierende Spalten
     */
    public function ensureTable(CustomTable $table): array
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

    /** Zeilen der Quelle (für den Fortschritt). */
    public function countSource(CustomTable $table): int
    {
        return DynamicRecord::forTable($table)->newQuery()->withoutGlobalScopes()->toBase()->count();
    }

    /**
     * Einen Block aus der Quelle in den Stand kopieren: bis $limit Zeilen nach den Schlüsselwerten $after (Keyset über
     * die Schlüsselspalten, bei mehreren verschachtelt – portabel). Ohne Schlüssel in einem Durchgang.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>|null  $after  Schlüsselwerte der letzten kopierten Zeile, null am Anfang
     * @return array{rows: int, after: array<string, mixed>|null, complete: bool}
     */
    public function copyBlock(CustomTable $table, DataSnapshot $snapshot, array $columns, ?array $after, int $limit): array
    {
        $keys = $table->keyColumns();
        $source = DynamicRecord::forTable($table)->newQuery()->withoutGlobalScopes()->toBase()->select($columns);

        if ($keys === []) {
            $records = $source->cursor();
            $complete = true;
        } else {
            foreach ($keys as $key) {
                $source->orderBy($key);
            }

            if ($after !== null) {
                $this->afterKeys($source, $keys, $after);
            }

            $records = $source->limit($limit)->get();
            $complete = $records->count() < $limit;
        }

        $types = $this->types($table, $columns);
        $target = $this->connection()->table($this->qualified($table));
        $chunkSize = max(1, min(500, intdiv(self::MAX_PLACEHOLDERS, count($columns) + 1)));
        $chunk = [];
        $count = 0;
        $last = $after;

        foreach ($records as $record) {
            $values = (array) $record;
            $row = [self::SNAPSHOT_COLUMN => $snapshot->id];

            foreach ($columns as $column) {
                $row[$column] = $this->normalize($values[$column] ?? null, $types[$column]);
            }

            $chunk[] = $row;
            $count++;
            $last = $keys === [] ? null : array_combine($keys, array_map(static fn (string $key): mixed => $values[$key] ?? null, $keys));

            if (count($chunk) >= $chunkSize) {
                (clone $target)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            (clone $target)->insert($chunk);
        }

        return ['rows' => $count, 'after' => $last, 'complete' => $complete];
    }

    /** Den Stand abschliessen: vollständig, Protokoll, Aufbewahrung durchsetzen. */
    public function complete(CustomTable $table, DataSnapshot $snapshot, int $rows, ?User $user): void
    {
        $snapshot->update(['status' => DataSnapshot::COMPLETE, 'row_count' => $rows, 'finished_at' => now()]);

        activity('data')->performedOn($table)->causedBy($user)->event('snapshot_created')
            ->withChanges(['attributes' => ['stand' => $snapshot->id, 'zeilen' => $rows]])
            ->log('snapshot_created');

        $this->prune($table);
    }

    /** Den Stand verwerfen: Zeilen weg, fehlgeschlagen mit Meldung; der vorherige Stand bleibt gültig. */
    public function fail(DataSnapshot $snapshot, string $message): void
    {
        $table = $snapshot->customTable;

        if ($table !== null) {
            $this->deleteRows($table, $snapshot->id);
        }

        $snapshot->update(['status' => DataSnapshot::FAILED, 'finished_at' => now(), 'message' => $message]);
        Log::warning('Datenstand fehlgeschlagen', ['table' => $table?->name, 'snapshot' => $snapshot->id, 'error' => $message]);
    }

    // --- intern ------------------------------------------------------------------------

    /** Stand „läuft“ und Arbeit anlegen, verknüpft; noch ohne Mitarbeit. */
    private function begin(CustomTable $table, ?User $user): WorkItem
    {
        $this->assertExternal($table);

        if ($this->busy($table)) {
            throw new BusinessRuleException(__('snapshots.errors.busy'), self::BUSY);
        }

        $snapshot = DataSnapshot::create([
            'custom_table_id' => $table->id,
            'status' => DataSnapshot::RUNNING,
            'started_at' => now(),
            'created_by' => $user?->id,
        ]);
        $item = $this->work()->start(SnapshotRefreshWork::KEY, ['table_id' => $table->id, 'snapshot_id' => $snapshot->id], $user, $table->app_id);
        $snapshot->update(['work_item_id' => $item->id]);

        return $item;
    }

    /**
     * Zeilen nach den Schlüsselwerten: (k1 > a1) OR (k1 = a1 AND k2 > a2) OR …
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $after
     */
    private function afterKeys(Builder $query, array $keys, array $after): void
    {
        $query->where(static function (Builder $outer) use ($keys, $after): void {
            foreach ($keys as $i => $key) {
                $outer->orWhere(static function (Builder $part) use ($keys, $after, $i): void {
                    for ($j = 0; $j < $i; $j++) {
                        $part->where($keys[$j], '=', $after[$keys[$j]] ?? null);
                    }

                    $part->where($keys[$i], '>', $after[$keys[$i]] ?? null);
                });
            }
        });
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

    /** Erst bei Bedarf: die Arten von Arbeit (darunter SnapshotRefreshWork) brauchen diesen Dienst. */
    private function work(): WorkService
    {
        return app(WorkService::class);
    }
}
