<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\CustomTableLookup;
use App\DynamicData\Rules\TableRule;
use App\Models\Base\CustomTable as BaseCustomTable;
use App\Models\Concerns\BelongsToApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadaten einer Tabelle: Designer-Tabelle (physisch im Namensraum app_data, Änderungen nur über
 * App\Services\DynamicSchemaService) oder angebundene externe Tabelle bzw. View (Table-Connector,
 * App\ExternalData\TableConnectorService; die externe Datenbank wird nie verändert).
 */
class CustomTable extends BaseCustomTable
{
    use BelongsToApp;
    use HasAuditLog;
    use HasStableKey;
    use HasTranslatedAttributes;

    /** Je Sprache (JSON); fehlt eine, gilt die Vorgabesprache. */
    public array $translatable = ['label', 'description'];

    protected static function booted(): void
    {
        // Je Request geladene Tabellen (CustomTableLookup) verwerfen, sobald sich eine ändert.
        $forget = static function (): void {
            app(CustomTableLookup::class)->forget();
        };
        static::saved($forget);
        static::deleted($forget);
    }

    public const string SOURCE_DESIGNER = 'designer';

    public const string SOURCE_EXTERNAL = 'external';

    protected $attributes = [
        'source' => self::SOURCE_DESIGNER,
        'is_active' => true,
        'export_enabled' => false,
        'import_enabled' => false,
        'allow_create' => false,
        'allow_update' => false,
        'allow_delete' => false,
    ];

    /** Schreibende Aktionen, die angebundene Tabellen je Tabelle freischalten. */
    public const array WRITE_ACTIONS = ['create' => 'allow_create', 'update' => 'allow_update', 'delete' => 'allow_delete'];

    /**
     * Name => Bezeichnung in der aktuellen Sprache, sortiert. Über das Modell, denn eine
     * Abfrage per pluck() lieferte das rohe JSON.
     *
     * @return array<string, string>
     */
    public static function labelOptions(bool $activeOnly = false, bool $designerOnly = false, ?int $appId = null): array
    {
        return self::query()
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->when($appId !== null, fn (Builder $q) => $q->where('app_id', $appId))
            ->when($designerOnly, fn (Builder $q) => $q->where('source', self::SOURCE_DESIGNER))
            ->get(['id', 'name', 'label'])
            ->mapWithKeys(fn (self $table): array => [$table->name => (string) $table->label])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    public function isExternal(): bool
    {
        return $this->source === self::SOURCE_EXTERNAL;
    }

    /**
     * Regeln auf Tabellenebene (z. B. „Ende ≥ Beginn“).
     *
     * @return list<TableRule>
     */
    public function tableRules(): array
    {
        return TableRule::listFrom($this->validation_rules);
    }

    /** Einzelne Schlüsselspalte; null bei Tabellen ohne oder mit zusammengesetztem Schlüssel. */
    public function keyColumn(): ?string
    {
        if (! $this->isExternal()) {
            return 'id';
        }

        $keys = (array) ($this->key_columns ?? []);

        return count($keys) === 1 ? (string) $keys[0] : null;
    }

    /** Ohne einzelnen Schlüssel gibt es nur Liste und Export, keine Details und keine Auswahl. */
    public function hasSingleKey(): bool
    {
        return $this->keyColumn() !== null;
    }

    /**
     * Schlüsselspalten angebundener Tabellen (eine oder mehrere); Designer-Tabellen: id.
     *
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return $this->isExternal() ? array_values(array_map('strval', (array) ($this->key_columns ?? []))) : ['id'];
    }

    /** Einzelner oder zusammengesetzter Schlüssel: Details und Bearbeiten möglich, Auswahl nur bei einzelnem. */
    public function hasKey(): bool
    {
        return $this->keyColumns() !== [];
    }

    /** Mehrere Schlüsselspalten (z. B. Zuordnungstabelle für n:m-Beziehungen). */
    public function hasCompositeKey(): bool
    {
        return count($this->keyColumns()) > 1;
    }

    /**
     * Kann eine angebundene Tabelle überhaupt beschrieben werden? Nur Tabellen (keine Views) mit
     * Schlüssel über eine Verbindung, die nicht auf „nur lesen“ steht. Ob die Datenbank den
     * Schlüssel als eindeutig garantiert, prüft der Table-Connector beim Freischalten.
     */
    public function canBeWritable(): bool
    {
        return $this->isExternal()
            && $this->external_kind !== 'view'
            && $this->hasKey()
            && $this->databaseConnection !== null
            && ! $this->databaseConnection->is_read_only;
    }

    /**
     * Bietet die Tabelle diese Aktion an? Designer-Tabellen: alle. Angebundene: Lesen und Export
     * immer, Schreiben nur, wenn freigeschaltet und möglich.
     */
    public function supports(string $action): bool
    {
        // Datenimport nur in Tabellen des Table-Designers.
        if ($action === 'import') {
            return ! $this->isExternal();
        }

        if (! $this->isExternal() || ! isset(self::WRITE_ACTIONS[$action])) {
            return true;
        }

        return (bool) $this->{self::WRITE_ACTIONS[$action]} && $this->canBeWritable();
    }

    public function isWritable(): bool
    {
        return ! $this->isExternal() || $this->supports('create') || $this->supports('update') || $this->supports('delete');
    }

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /** @return HasMany<DataSnapshot, $this> Datenstände (nur angebundene Tabellen) */
    public function snapshots(): HasMany
    {
        return $this->hasMany(DataSnapshot::class);
    }

    /** Neuester vollständiger Datenstand – mit ihm rechnen Auswertungen. */
    public function latestSnapshot(): ?DataSnapshot
    {
        return $this->snapshots()->where('status', DataSnapshot::COMPLETE)->orderByDesc('finished_at')->orderByDesc('id')->first();
    }

    /** @return HasMany<CustomField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(CustomField::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function designer(Builder $query): void
    {
        $query->where('source', self::SOURCE_DESIGNER);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function external(Builder $query): void
    {
        $query->where('source', self::SOURCE_EXTERNAL);
    }
}
