<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

use App\DynamicData\CustomTableLookup;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Berechnete Felder ausrechnen: beim Speichern eines Datensatzes (DynamicRecord), für die Master-Datensätze
 * eines geänderten Kind-Datensatzes (Summen & Co.) und für alle Datensätze einer Tabelle, z. B. nach dem
 * Ändern einer Formel oder per Befehl.
 */
final class Recalculator
{
    /** Schutz vor Endlosschleifen bei fehlerhaften Daten (z. B. Kreis in einer Baumstruktur). */
    private const int MAX_DEPTH = 20;

    private int $depth = 0;

    public function __construct(private readonly Evaluator $evaluator) {}

    /**
     * Berechnete Felder des Datensatzes setzen (in Rechenreihenfolge).
     *
     * @param  array<string, array{ast: array<string, mixed>, scale: int}>  $computed
     */
    public function apply(DynamicRecord $record, array $computed): void
    {
        $aggregate = static fn (array $node): mixed => self::aggregate($node, $record->getKey());

        foreach ($computed as $name => $definition) {
            $value = Evaluator::round(
                $this->evaluator->evaluate($definition['ast'], static fn (string $field): mixed => $record->getAttribute($field), $aggregate),
                $definition['scale'],
            );

            $record->setAttribute($name, $value === null ? null : ($definition['scale'] === 0 ? (int) $value : $value));
        }
    }

    /**
     * Summe & Co. über die nicht gelöschten Kind-Datensätze. Ein neuer Datensatz hat noch keine.
     *
     * @param  array<string, mixed>  $node
     */
    public static function aggregate(array $node, mixed $key): mixed
    {
        $function = (string) $node['agg'];

        if ($key === null) {
            return in_array($function, ['sum', 'count'], true) ? 0 : null;
        }

        try {
            $query = DynamicRecord::forTableName((string) $node['table'])->newQuery()->where((string) $node['via'], $key);
        } catch (ModelNotFoundException) {
            return null;
        }

        if ($function === 'count') {
            return $query->count();
        }

        // Einzelnes Feld: die Datenbank fasst zusammen. Ausdruck: je Kind-Datensatz exakt rechnen.
        return isset($node['expr'])
            ? self::fold($function, $query->get()->map(static fn (DynamicRecord $child): ?string => (new Evaluator)->evaluate(
                (array) $node['expr'],
                static fn (string $field): mixed => $child->getAttribute($field),
            ))->filter(static fn (?string $v): bool => $v !== null)->values()->all())
            : $query->{$function}((string) $node['field']);
    }

    /**
     * Ergebnisse je Kind-Datensatz zusammenfassen; leere zählen wie in SQL nicht mit.
     *
     * @param  list<string>  $values
     */
    private static function fold(string $function, array $values): ?string
    {
        if ($values === []) {
            return $function === 'sum' ? '0' : null;
        }

        $sum = array_reduce($values, static fn (string $carry, string $v): string => bcadd($carry, $v, 12), '0');

        return match ($function) {
            'sum' => $sum,
            'avg' => bcdiv($sum, (string) count($values), 12),
            'min' => array_reduce($values, static fn (?string $carry, string $v): string => $carry === null || bccomp($v, $carry, 12) < 0 ? $v : $carry),
            'max' => array_reduce($values, static fn (?string $carry, string $v): string => $carry === null || bccomp($v, $carry, 12) > 0 ? $v : $carry),
            default => null,
        };
    }

    /**
     * Ein Kind-Datensatz wurde gespeichert, gelöscht oder wiederhergestellt: betroffene Master-Datensätze
     * neu berechnen (beim Wechsel der Verknüpfung der alte und der neue). Deren Speichern setzt sich nach
     * oben fort.
     *
     * @param  list<array{table: string, via: string}>  $dependents
     */
    public function propagate(DynamicRecord $child, array $dependents): void
    {
        if ($dependents === [] || $this->depth >= self::MAX_DEPTH) {
            return;
        }

        $this->depth++;

        try {
            foreach ($dependents as $dependent) {
                $keys = array_unique(array_filter([$child->getAttribute($dependent['via']), $child->getOriginal($dependent['via'])], static fn (mixed $k): bool => $k !== null));

                foreach ($keys as $key) {
                    $parent = DynamicRecord::forTableName($dependent['table'])->newQuery()->withTrashed()->find($key);

                    if ($parent instanceof DynamicRecord) {
                        $parent->recalculate();

                        if ($parent->isDirty()) {
                            app(RecordWriter::class)->save($parent);
                        }
                    }
                }
            }
        } finally {
            $this->depth--;
        }
    }

    /** Datensätze je Block (eine Transaktion je Block). */
    public const int BLOCK = 500;

    /**
     * Alle Datensätze einer Tabelle neu berechnen (auch gelöschte), danach die Tabellen, die über sie summieren –
     * synchron, für Seeder und Konsole. Der Table-Designer startet stattdessen die Arbeit „Berechnete Felder neu
     * berechnen“ (Fundament 2, F3b), die dieselben Blöcke über recomputeBlock() abarbeitet.
     *
     * @param  array<string, true>  $done  bereits berechnete Tabellen
     * @return int Zahl der geänderten Datensätze
     */
    public function recomputeTable(CustomTable $table, array &$done = []): int
    {
        $changed = 0;

        foreach ($this->recomputeOrder([$table], $done) as $next) {
            $after = 0;

            do {
                $block = $this->recomputeBlock($next, $after);
                $changed += $block['changed'];
                $after = $block['last_id'];
            } while ($block['count'] === self::BLOCK);
        }

        return $changed;
    }

    /**
     * Reihenfolge der Nachberechnung: jede Tabelle, gefolgt von den Tabellen, die über sie summieren (rekursiv), jede
     * nur einmal.
     *
     * @param  iterable<CustomTable>  $tables
     * @param  array<string, true>  $done  bereits berechnete bzw. eingereihte Tabellen
     * @return list<CustomTable>
     */
    public function recomputeOrder(iterable $tables, array &$done = []): array
    {
        $order = [];

        foreach ($tables as $table) {
            if (isset($done[$table->name])) {
                continue;
            }

            $done[$table->name] = true;
            $order[] = $table;
            array_push($order, ...$this->recomputeOrder(self::parentsOf($table->name), $done));
        }

        return $order;
    }

    /**
     * Einen Block Datensätze (nach ID, ab $afterId) neu berechnen, in einer Transaktion – ohne Protokoll und ohne neuen
     * Änderungszeitpunkt, denn Auslöser ist die Formel, nicht eine Änderung am Datensatz. Grundlage von
     * recomputeTable() und der Arbeit „Berechnete Felder neu berechnen“ (Fundament 2, F3a).
     *
     * @return array{count: int, changed: int, last_id: int}
     */
    public function recomputeBlock(CustomTable $table, int $afterId, int $limit = self::BLOCK): array
    {
        if ($afterId === 0) {
            DynamicRecord::forget();
        }

        $model = DynamicRecord::forTable($table->loadMissing('fields'));
        $records = $model->newQuery()->withTrashed()->where($model->getKeyName(), '>', $afterId)->orderBy($model->getKeyName())->limit($limit)->get();
        $writer = app(RecordWriter::class);
        $changed = 0;

        // Ein Block je Transaktion; der Writer schreibt ohne Phasen, Protokoll und Zeitstempel.
        $writer->batch(function () use ($records, $writer, &$changed): void {
            foreach ($records as $record) {
                /** @var DynamicRecord $record */
                $record->recalculate();

                if ($record->isDirty()) {
                    $writer->persist($record);
                    $changed++;
                }
            }
        }, WriteOptions::defaults()->withOrigin(WriteOptions::RECOMPUTE));

        return ['count' => $records->count(), 'changed' => $changed, 'last_id' => (int) ($records->last()?->getKey() ?? $afterId)];
    }

    /**
     * Tabellen mit berechneten Feldern, die über diese Tabelle summieren (zählen, …), mit der Verknüpfung.
     *
     * @return list<array{table: string, via: string}>
     */
    public static function dependentsOf(string $table): array
    {
        $dependents = [];

        // Berechnete Felder je Request einmal laden (B-150): jede Tabellendefinition fragt sonst neu.
        $computed = app(CustomTableLookup::class)->remember('computed-fields', static fn () => CustomField::query()
            ->with('custom_table')->where('type', FieldType::Computed->value)->get());

        foreach ($computed as $field) {
            if (! $field->is_active || ! $field->custom_table instanceof CustomTable) {
                continue;
            }

            foreach (FormulaAnalyzer::aggregates((array) ($field->optionsArray()['ast'] ?? [])) as $aggregate) {
                if ($aggregate['table'] === $table) {
                    $dependents[$field->custom_table->name.'|'.$aggregate['via']] = ['table' => $field->custom_table->name, 'via' => $aggregate['via']];
                }
            }
        }

        return array_values($dependents);
    }

    /**
     * @return list<CustomTable>
     */
    private static function parentsOf(string $table): array
    {
        $names = array_unique(array_column(self::dependentsOf($table), 'table'));

        return CustomTable::query()->with('fields')->whereIn('name', $names)->get()->all();
    }
}
