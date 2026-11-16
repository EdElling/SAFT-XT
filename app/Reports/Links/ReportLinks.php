<?php

declare(strict_types=1);

namespace App\Reports\Links;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Schema\FieldType;
use App\ExternalData\Snapshots\SnapshotService;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\ReportLink;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;

/**
 * Regeln der Tabellenverknüpfungen: welche Felder als Quelle bzw. Ziel taugen, ob sie zueinander
 * passen, und wie viele Werte der Quelle im Ziel gefunden werden.
 *
 * Ziel muss eindeutig sein (SAFT-Tabelle: id oder Feld „eindeutig“; angebunden: der einzelne Schlüssel),
 * sonst vervielfachten sich Zeilen. Verglichen wird nur Ganzzahl mit Ganzzahl und Text mit Text – eine
 * Umwandlung bräuchte datenbankspezifisches SQL.
 */
final class ReportLinks
{
    public const string KIND_INTEGER = 'integer';

    public const string KIND_TEXT = 'text';

    /** Schlüssel der Zielauswahl für die Spalte id einer SAFT-Tabelle. */
    public const string ID = 'id';

    public function __construct(
        private readonly DynamicDataNamespace $namespace,
        private readonly SnapshotService $snapshots,
    ) {}

    /** Werteart für den Vergleich, null = als Verknüpfungsfeld ungeeignet. */
    public static function kind(CustomField $field): ?string
    {
        if (in_array($field->external_type, ['json', 'jsonb'], true)) {
            return null;
        }

        return match (TableConnectorService::baseType($field)) {
            FieldType::Integer => self::KIND_INTEGER,
            FieldType::String, FieldType::Select => self::KIND_TEXT,
            default => null,
        };
    }

    /** @return array<int, string> Felder, die als Quelle taugen (ID => Bezeichnung) */
    public function sourceOptions(CustomTable $table): array
    {
        return $table->fields()->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn (CustomField $f): bool => self::kind($f) !== null)
            ->mapWithKeys(fn (CustomField $f): array => [$f->id => $f->label.' ('.$f->name.')'])
            ->all();
    }

    /**
     * Eindeutige Felder der Zieltabelle, optional nur passend zu einer Werteart.
     *
     * @return array<int|string, string>
     */
    public function targetOptions(CustomTable $table, ?string $kind = null): array
    {
        $options = [];

        if (! $table->isExternal() && ($kind === null || $kind === self::KIND_INTEGER)) {
            $options[self::ID] = 'id';
        }

        foreach ($this->targetFields($table) as $field) {
            if ($kind === null || self::kind($field) === $kind) {
                $options[$field->id] = $field->label.' ('.$field->name.')';
            }
        }

        return $options;
    }

    /** @return list<CustomField> */
    private function targetFields(CustomTable $table): array
    {
        $fields = $table->fields()->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn (CustomField $f): bool => self::kind($f) !== null);

        return array_values(($table->isExternal()
            ? $fields->filter(fn (CustomField $f): bool => $table->hasSingleKey() && $f->name === $table->keyColumn())
            : $fields->filter(fn (CustomField $f): bool => $f->is_unique))->all());
    }

    /**
     * Fehler der Angaben oder null. $target: Feld-ID oder ReportLinks::ID.
     */
    public function problem(?CustomTable $source, ?CustomField $sourceField, ?CustomTable $target, int|string|null $targetField): ?string
    {
        if ($source === null || $target === null || $sourceField === null || $targetField === null || $targetField === '') {
            return null;
        }

        if ($sourceField->custom_table_id !== $source->id || ! $sourceField->is_active || self::kind($sourceField) === null) {
            return __('report_links.errors.source_field');
        }

        if (! array_key_exists($targetField, $this->targetOptions($target))) {
            return __('report_links.errors.target_unique', ['table' => $target->label]);
        }

        $targetKind = $targetField === self::ID ? self::KIND_INTEGER : self::kind(CustomField::query()->findOrFail((int) $targetField));

        if ($targetKind !== self::kind($sourceField)) {
            return __('report_links.errors.kind_mismatch');
        }

        if ($source->is($target) && (string) $targetField === (string) $sourceField->id) {
            return __('report_links.errors.same_field');
        }

        return null;
    }

    /**
     * Wie viele Zeilen der Quelle mit Wert finden ein Ziel? Angebundene Tabellen über ihren neuesten
     * Datenstand; fehlt er, null.
     *
     * @return array{matched: int, total: int}|null
     */
    public function coverage(CustomTable $source, CustomField $sourceField, CustomTable $target, string $targetColumn): ?array
    {
        if (($source->isExternal() && $source->latestSnapshot() === null) || ($target->isExternal() && $target->latestSnapshot() === null)) {
            return null;
        }

        $query = $this->from($source, 's')
            ->whereNotNull('s.'.$sourceField->name)
            ->leftJoin($this->name($target).' as t', function (JoinClause $on) use ($sourceField, $target, $targetColumn): void {
                $on->on('t.'.$targetColumn, '=', 's.'.$sourceField->name);
                $this->current($on, $target, 't');
            });

        try {
            return [
                'total' => (clone $query)->count(),
                'matched' => (clone $query)->whereNotNull('t.'.$targetColumn)->count(),
            ];
        } catch (QueryException) {
            // z. B. Spalte noch nicht im Datenstand: keine Aussage statt Fehler.
            return null;
        }
    }

    public function coverageText(ReportLink|array $link): ?string
    {
        [$source, $field, $target, $column] = $link instanceof ReportLink
            ? [$link->sourceTable, $link->sourceField, $link->targetTable, $link->targetColumn()]
            : $link;

        if ($source === null || $field === null || $target === null) {
            return null;
        }

        $coverage = $this->coverage($source, $field, $target, $column);

        if ($coverage === null) {
            return __('report_links.coverage_no_snapshot');
        }

        return __('report_links.coverage', [
            'matched' => number_format($coverage['matched'], 0, ',', '\''),
            'total' => number_format($coverage['total'], 0, ',', '\''),
            'percent' => $coverage['total'] === 0 ? 0 : (int) round(100 * $coverage['matched'] / $coverage['total']),
        ]);
    }

    private function from(CustomTable $table, string $alias): Builder
    {
        $query = $this->namespace->connection()->table($this->name($table).' as '.$alias);
        $this->current($query, $table, $alias);

        return $query;
    }

    private function name(CustomTable $table): string
    {
        return $table->isExternal() ? $this->snapshots->qualified($table) : $this->namespace->qualify($table->name);
    }

    private function current(Builder $query, CustomTable $table, string $alias): void
    {
        $table->isExternal()
            ? $query->where($alias.'.'.SnapshotService::SNAPSHOT_COLUMN, (int) $table->latestSnapshot()?->id)
            : $query->whereNull($alias.'.deleted_at');
    }
}
