<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\CustomTableLookup;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Eloquent\OptionalSoftDeletingScope;
use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Schema\IdentifierValidator;
use App\DynamicData\TableDefinition;
use App\DynamicData\Ui\KeylessRow;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteContext;
use App\DynamicData\Write\WritePhases;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\ConnectionRegistry;
use App\ExternalData\ExternalTableUnavailable;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Generisches Model für Tabellen des Table-Designers (Verbindung app_data) und für
 * angebundene externe Tabellen (Table-Connector, Verbindung ext_<id>, vorerst nur lesend).
 *
 * Konfiguration zur Laufzeit über ein Register (Tabellenname -> Definition): Eloquent
 * erzeugt intern laufend neue Instanzen ("new static"), die sonst ohne $fillable/Casts
 * wären. Das Audit-Log protokolliert daher alle Nicht-System-Spalten (logAll) statt
 * $fillable – auch Spatie erzeugt für Vorher-Werte unkonfigurierte Instanzen.
 */
class DynamicRecord extends Model
{
    use HasAuditLog;
    use SoftDeletes {
        performDeleteOnModel as private softDeleteOnModel;
    }

    public const string MORPH_PREFIX = 'dynamic:';

    /** @var array<string, TableDefinition> */
    private static array $definitions = [];

    private ?string $definitionName = null;

    public static function forTable(CustomTable $table): self
    {
        $definition = self::$definitions[$table->name] ??= $table->isExternal()
            ? TableDefinition::fromExternalTable($table, self::externalConnection($table))
            : TableDefinition::fromCustomTable($table, app(DynamicDataNamespace::class)->qualify($table->name));

        return (new self)->applyDefinition($definition);
    }

    /** Registriert die Verbindung der externen Tabelle und liefert ihren Namen. */
    private static function externalConnection(CustomTable $table): string
    {
        $connection = $table->databaseConnection()->first();

        if ($connection === null || ! $connection->is_active) {
            throw ExternalTableUnavailable::for((string) $table->label);
        }

        return app(ConnectionRegistry::class)->connection($connection)->getName();
    }

    /** Ersetzt den Scope von SoftDeletes: deleted_at nur dort, wo es die Spalte gibt. */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(SoftDeletingScope::class, new OptionalSoftDeletingScope);
    }

    /** Angebundene Tabellen haben kein deleted_at: Löschen ist dort endgültig. */
    protected function performDeleteOnModel()
    {
        if ($this->hasSoftDeleteColumn()) {
            return $this->softDeleteOnModel();
        }

        $this->assertSingleRow();
        $this->setKeysForSaveQuery($this->newModelQuery())->delete();
        $this->exists = false;
    }

    /** Berechnete Felder (Table-Designer) aus den übrigen Werten des Datensatzes setzen. */
    public function recalculate(): void
    {
        $computed = $this->definition()?->computed ?? [];

        if ($computed !== []) {
            app(Recalculator::class)->apply($this, $computed);
        }
    }

    /** Angebundene Tabellen: zweite Sicherung, dass Bearbeiten und Löschen genau eine Zeile treffen. */
    protected function performUpdate(Builder $query)
    {
        if (! $this->hasSoftDeleteColumn() && $this->isDirty()) {
            $this->assertSingleRow();
        }

        return parent::performUpdate($query);
    }

    private function assertSingleRow(): void
    {
        if ($this->setKeysForSaveQuery($this->newModelQuery())->count() !== 1) {
            throw new BusinessRuleException(__('data.errors.row_not_unique'), 'dynamic.row_not_unique');
        }
    }

    /**
     * Zusammengesetzter Schlüssel: die Zeile über alle Schlüsselspalten (mit ihren ursprünglichen
     * Werten) finden; Eloquent kennt nur einen Schlüssel.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $columns = $this->compositeKey();

        if ($columns === []) {
            return parent::setKeysForSaveQuery($query);
        }

        foreach ($columns as $column) {
            $query->where($this->qualifyColumn($column), '=', $this->getOriginal($column) ?? $this->getAttribute($column));
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->compositeKey() === [] ? parent::setKeysForSelectQuery($query) : $this->setKeysForSaveQuery($query);
    }

    /** @return list<string> Spalten eines zusammengesetzten Schlüssels; leer bei einzelnem */
    public function compositeKey(): array
    {
        return $this->definition()?->compositeKey ?? [];
    }

    /**
     * Kennung für Protokoll und Verlauf: der Schlüssel bzw. bei zusammengesetztem Schlüssel die
     * kodierten Werte aller Schlüsselspalten (wie in der Liste).
     */
    public function activityKey(): int|string|null
    {
        $columns = $this->compositeKey();

        if ($columns === []) {
            return $this->getKey();
        }

        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $this->getRawOriginal($column) ?? $this->getAttributes()[$column] ?? null;
        }

        return KeylessRow::encode($values);
    }

    /**
     * Das Protokoll speichert Schlüssel als Zahl (subject_id). Text- und UUID-Schlüssel angebundener
     * Tabellen stehen stattdessen in den Eigenschaften („key“).
     */
    public function beforeActivityLogged(Model $activity, string $event): void
    {
        $properties = collect($activity->getAttribute('properties') ?? []);

        // Woher die Änderung kam (Datenansicht, Import, Plugin …), siehe WriteOptions::$origin.
        if (WriteContext::active()) {
            $properties->put('via', WriteContext::options()->origin);

            if (WriteContext::options()->overwriting) {
                $properties->put('overwrote', true);
            }
        }

        $key = $this->activityKey();

        if ($key !== null && ! is_int($key) && ! ctype_digit((string) $key)) {
            $activity->setAttribute('subject_id', null);
            $properties->put('key', (string) $key);
        }

        $activity->setAttribute('properties', $properties);
    }

    public function hasSoftDeleteColumn(): bool
    {
        return $this->definition()?->systemColumns ?? true;
    }

    /**
     * Die Zeile dieses Datensatzes, wie sie jetzt in der Datenbank steht (auch im Papierkorb), für die optimistische
     * Sperre des Writers – mit lock: true gesperrt bis zum Ende der Transaktion (FOR UPDATE, portabel).
     *
     * @internal
     */
    public function currentRow(bool $lock = false): ?self
    {
        $query = $this->newQueryWithoutScopes();

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var self|null $row */
        $row = $this->setKeysForSelectQuery($query)->first();

        return $row;
    }

    /** Eindeutiger Schlüssel vorhanden; sonst ist getKey() nur eine Spalte zum Lesen. */
    public function hasUniqueKey(): bool
    {
        return $this->definition()?->hasKey ?? true;
    }

    public function isWritable(): bool
    {
        return $this->definition()?->writable ?? true;
    }

    /**
     * Definition der Tabelle für die Phasen des Schreibwegs (App\DynamicData\Write).
     *
     * @internal kein Teil der Plugin-API
     */
    public function definition(): ?TableDefinition
    {
        return $this->definitionName === null ? null : (self::$definitions[$this->definitionName] ?? null);
    }

    /**
     * @throws ModelNotFoundException wenn die Tabelle im Table-Designer nicht (mehr) existiert
     */
    public static function forTableName(string $name): self
    {
        if (isset(self::$definitions[$name])) {
            return (new self)->applyDefinition(self::$definitions[$name]);
        }

        return self::forTable(CustomTable::query()->with('fields')->where('name', $name)->firstOrFail());
    }

    public static function isMorphType(?string $type): bool
    {
        return is_string($type) && str_starts_with($type, self::MORPH_PREFIX);
    }

    public static function forMorphType(string $type): ?self
    {
        try {
            return self::forTableName(substr($type, strlen(self::MORPH_PREFIX)));
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /** Nach Schemaänderungen aufrufen (DynamicSchemaService). */
    public static function forget(?string $name = null): void
    {
        if ($name === null) {
            self::$definitions = [];
        } else {
            unset(self::$definitions[$name]);
        }

        app(CustomTableLookup::class)->forget($name);
    }

    /** Tabelle des Table-Designers zu diesem Datensatz (für Dateifelder; null bei unbekannter Tabelle). */
    public function customTable(): ?CustomTable
    {
        return $this->definitionName === null ? null : app(CustomTableLookup::class)->byName($this->definitionName);
    }

    public function definitionName(): string
    {
        return $this->definitionName ?? throw new LogicException('DynamicRecord ist keiner Tabelle zugeordnet – DynamicRecord::forTable() verwenden.');
    }

    /**
     * Neue Instanzen (Hydration, create(), replicate() …) erben die Tabellen-Definition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newInstance($attributes = [], $exists = false): static
    {
        $model = new static;
        $model->exists = $exists;

        if (($definition = $this->definition()) !== null) {
            $model->applyDefinition($definition);
        } else {
            $model->definitionName = $this->definitionName;
            $model->setConnection($this->getConnectionName());
            $model->setTable($this->getTable());
            $model->fillable($this->getFillable());
            $model->mergeCasts($this->casts);
        }

        $model->fill((array) $attributes);

        return $model;
    }

    /** Eindeutig je Tabelle – sonst wäre subject_type im Audit-Log mehrdeutig. */
    public function getMorphClass(): string
    {
        return self::MORPH_PREFIX.$this->definitionName();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('dynamic.'.($this->definitionName ?? 'unknown'))
            ->logAll()
            ->logExcept(IdentifierValidator::SYSTEM_COLUMNS)
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Nachher-Phase des Schreibwegs (Summen in Master-Datensätzen, Dateien, Ereignisse für Plugins) – in den
     * Eloquent-Ereignissen, weil dort die Originalwerte noch die vor der Änderung sind. Nur während der Writer den
     * Datensatz schreibt; die Vorher-Phase ruft der Writer selbst (App\DynamicData\Write\WritePhases).
     */
    protected static function booted(): void
    {
        static::saved(static function (self $record): void {
            if (WriteContext::performing($record)) {
                app(WritePhases::class)->afterSave($record, WriteContext::creating($record), WriteContext::options());
            }
        });

        static::deleted(static function (self $record): void {
            if (WriteContext::performing($record)) {
                app(WritePhases::class)->afterDelete($record, $record->isForceDeleting() || ! $record->hasSoftDeleteColumn(), WriteContext::options());
            }
        });
    }

    /**
     * Schreiben läuft über den RecordWriter (Fundament 2, F1): Transaktion, Vorher- und Nachher-Phase, Ereignisse.
     * Ausserhalb des Writers leitet das Model dorthin; nur der Writer selbst führt den Eloquent-Schreibvorgang aus.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (WriteContext::performing($this)) {
            return parent::save($options);
        }

        app(RecordWriter::class)->save($this);

        return true;
    }

    public function delete(): ?bool
    {
        if (WriteContext::performing($this)) {
            return parent::delete();
        }

        app(RecordWriter::class)->delete($this);

        return true;
    }

    /**
     * Ohne Ereignisse für Plugins ausführen – für feste Datensätze wie die Demo-Daten, auf die Geschäftsregeln von
     * Plugins nicht wirken sollen.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function withoutPluginEvents(\Closure $callback): mixed
    {
        return WriteContext::withoutPluginEvents($callback);
    }

    private function applyDefinition(TableDefinition $definition): static
    {
        $this->definitionName = $definition->name;
        $this->setConnection($definition->connection);
        $this->setTable($definition->qualifiedTable);
        $this->setKeyName($definition->keyName);
        $this->setKeyType($definition->keyType);
        $this->setIncrementing($definition->incrementing);
        $this->timestamps = $definition->systemColumns;
        $this->fillable($definition->fillable);
        $this->mergeCasts($definition->casts);

        return $this;
    }
}
