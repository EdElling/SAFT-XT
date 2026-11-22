<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\DeleteGuard;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Eloquent\OptionalSoftDeletingScope;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Schema\IdentifierValidator;
use App\DynamicData\TableDefinition;
use App\DynamicData\Ui\KeylessRow;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\ConnectionRegistry;
use App\ExternalData\ExternalTableUnavailable;
use App\Models\Concerns\HasAuditLog;
use App\Plugins\Api\Events\RecordDeleted;
use App\Plugins\Api\Events\RecordDeleting;
use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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

    /** Für die Ereignisse der Plugins: wurde beim letzten Speichern angelegt? */
    private bool $creatingForEvents = false;

    /** Ereignisse für Plugins vorübergehend aus (Demo-Daten einspielen). */
    private static bool $pluginEventsMuted = false;

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
        $key = $this->activityKey();

        if ($key === null || is_int($key) || ctype_digit((string) $key)) {
            return;
        }

        $activity->setAttribute('subject_id', null);
        $activity->setAttribute('properties', collect($activity->getAttribute('properties') ?? [])->put('key', (string) $key));
    }

    public function hasSoftDeleteColumn(): bool
    {
        return $this->definition()?->systemColumns ?? true;
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

    private function definition(): ?TableDefinition
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
    }

    /** Tabelle des Table-Designers zu diesem Datensatz (für Dateifelder; null bei unbekannter Tabelle). */
    public function customTable(): ?CustomTable
    {
        return $this->definitionName === null ? null : CustomTable::query()->with('fields')->where('name', $this->definitionName)->first();
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

    protected static function booted(): void
    {
        // Externe Tabellen sind vorerst nur lesbar – auch außerhalb der Oberfläche.
        static::saving(static function (self $record): void {
            if (! $record->isWritable()) {
                throw new LogicException("Tabelle {$record->definitionName()} ist nur lesbar.");
            }
        });

        static::deleting(static function (self $record): void {
            if (! $record->isWritable()) {
                throw new LogicException("Tabelle {$record->definitionName()} ist nur lesbar.");
            }
        });

        static::creating(static function (self $record): void {
            if (! $record->hasSoftDeleteColumn()) {
                // Leere Werte nicht als NULL schreiben, wo die Datenbank einen Vorgabewert hat (z. B. Status „geplant“).
                foreach ($record->definition()?->databaseDefaults ?? [] as $column) {
                    if ($record->getAttribute($column) === null) {
                        unset($record->attributes[$column]);
                    }
                }

                // MySQL meldet eine UUID-Vorgabe nicht zurück: Schlüssel selbst vergeben.
                if (($record->definition()?->uuidKey ?? false) && blank($record->getKey())) {
                    $record->setAttribute($record->getKeyName(), (string) Str::uuid());
                }

                self::dispatchPluginEvent(RecordSaving::class, $record);

                return;
            }

            // Vorgaben aus dem Master-Datensatz für leere Felder (Kopie, z. B. Einzelpreis ← Artikel → Preis).
            foreach ($record->definition()?->copies ?? [] as $field => $copy) {
                if ($record->getAttribute($field) === null) {
                    $record->setAttribute($field, CopySource::lookup($copy['table'], $copy['source'], $record->getAttribute($copy['relation'])));
                }
            }

            // Fortlaufende Felder, die leer geblieben sind (z. B. nicht im Formular): höchster Wert + 1.
            foreach ($record->definition()?->sequences ?? [] as $field => $scope) {
                if ($record->getAttribute($field) === null) {
                    $next = SequenceSuggester::nextIn($record, $field, $scope, $scope === null ? null : $record->getAttribute($scope));

                    if ($next !== null) {
                        $record->setAttribute($field, $next);
                    }
                }
            }

            // Plugins vor den Formeln: Werte, die ein Listener setzt, fliessen in berechnete Felder ein.
            self::dispatchPluginEvent(RecordSaving::class, $record);

            $record->recalculate();

            $record->created_by ??= auth()->id();
            $record->updated_by = auth()->id() ?? $record->created_by;
        });

        static::updating(static function (self $record): void {
            if (! $record->hasSoftDeleteColumn()) {
                // Schlüssel angebundener Tabellen sind fest (sonst verlören Verknüpfungen und Protokoll ihren Bezug).
                if ($record->isDirty($record->compositeKey() === [] ? $record->getKeyName() : $record->compositeKey())) {
                    throw new LogicException("Der Schlüssel von {$record->definitionName()} ist nicht änderbar.");
                }

                self::dispatchPluginEvent(RecordSaving::class, $record);

                return;
            }

            self::dispatchPluginEvent(RecordSaving::class, $record);

            $record->recalculate();

            $record->updated_by = auth()->id() ?? $record->updated_by;
        });

        // Summen & Co. in Master-Datensätzen nachführen (berechnete Felder, Table-Designer).
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, static function (self $record): void {
                if ($record->hasSoftDeleteColumn() && ($dependents = $record->definition()?->dependents ?? []) !== []) {
                    app(Recalculator::class)->propagate($record, $dependents);
                }
            });
        }

        // Löschsperre, solange aktive Detail-Datensätze per Verknüpfung auf diesen zeigen.
        static::deleting(static function (self $record): void {
            app(DeleteGuard::class)->assertDeletable($record);
            self::dispatchPluginEvent(RecordDeleting::class, $record);
        });

        // Dateifelder (B-021): neue Dateien zuordnen, ersetzte und entfernte löschen; endgültig gelöscht → Dateien weg.
        // Im Papierkorb bleiben die Dateien, damit Wiederherstellen sie zurückbringt.
        static::saving(static function (self $record): void {
            if (($record->definition()?->files ?? []) !== [] && ($table = $record->customTable()) !== null) {
                app(RecordFiles::class)->assertDocumentsKept($record, $table);
            }
        });
        static::saved(static function (self $record): void {
            if (($record->definition()?->files ?? []) !== [] && ($table = $record->customTable()) !== null) {
                app(RecordFiles::class)->sync($record, $table);
            }
        });
        static::forceDeleted(static function (self $record): void {
            if (($record->definition()?->files ?? []) !== [] && ($table = $record->customTable()) !== null) {
                app(RecordFiles::class)->deleteForRecord($table, (string) $record->getKey());
            }
        });

        // Ereignisse für Plugins (nach SAFTs eigenen Nacharbeiten).
        static::saved(static fn (self $record) => self::dispatchPluginEvent(RecordSaved::class, $record));
        static::deleted(static fn (self $record) => self::dispatchPluginEvent(RecordDeleted::class, $record));
    }

    /**
     * Ereignis der Programmierschnittstelle auslösen – nur wenn ein Plugin zuhört. RecordSaving und RecordDeleting
     * laufen nach SAFTs eigenen Vorgaben (fortlaufend, Übernehmen aus, berechnete Felder, Löschsperre).
     *
     * @param  class-string<RecordEvent>  $event
     */
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
        $muted = self::$pluginEventsMuted;
        self::$pluginEventsMuted = true;

        try {
            return $callback();
        } finally {
            self::$pluginEventsMuted = $muted;
        }
    }

    private static function dispatchPluginEvent(string $event, self $record): void
    {
        if (self::$pluginEventsMuted) {
            return;
        }

        // Ob angelegt oder geändert wird, steht nur vor dem Speichern sicher fest (wasRecentlyCreated bleibt am Objekt).
        if ($event === RecordSaving::class) {
            $record->creatingForEvents = ! $record->exists;
        }

        if (Event::hasListeners($event)) {
            Event::dispatch(new $event($record->definitionName(), $record, in_array($event, [RecordSaving::class, RecordSaved::class], true) && $record->creatingForEvents));
        }
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
