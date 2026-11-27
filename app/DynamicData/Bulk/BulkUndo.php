<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\Write\LoggedValue;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\BulkChange as BulkChangeHead;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Rücknahme einer Sammelaktion (B-133 S3, BULK_EDIT.md §13): liest je Block die Einträge des Laufs aus dem
 * Änderungsprotokoll (Kennung „run“, über den Index auf subject_type/subject_id) und ordnet jeden Datensatz ein.
 *
 * - Ändern: Stehen in allen Feldern der Sammeländerung noch die geschriebenen Werte, kommen die alten zurück. Weicht ein
 *   Feld ab, ist der Datensatz seither geändert und bleibt ganz stehen. Stehen schon die alten Werte da, ist er schon
 *   zurück. Andere Felder zählen nicht (Frage 2). Je Datensatz gilt der alte Wert aus dem ersten und der neue aus dem
 *   letzten Eintrag des Laufs (Frage 14).
 * - Löschen: im Papierkorb → wiederherstellen; nicht mehr im Papierkorb → schon zurück.
 * - Wiederherstellen: nicht im Papierkorb → erneut löschen; im Papierkorb → schon zurück.
 */
final readonly class BulkUndo
{
    public const string REVERT = 'revert';

    public const string ALREADY = 'already';

    public const string CHANGED = 'changed';

    public const string NOT_LOGGED = 'not_logged';

    public const string GONE = 'gone';

    public function __construct(public BulkChangeHead $head, public CustomTable $table) {}

    public static function of(int $headId, CustomTable $table): self
    {
        $head = BulkChangeHead::query()->find($headId);

        if ($head === null || $head->custom_table_id !== $table->id) {
            throw new BusinessRuleException(__('work.bulk.undo.missing'), 'bulk.undo_missing');
        }

        return new self($head, $table);
    }

    /** @return list<string> Felder der Sammeländerung, die es noch gibt und die aktiv sind */
    public function fields(): array
    {
        $active = $this->table->fields->filter(static fn (CustomField $f): bool => $f->is_active)->pluck('name')->all();

        return array_values(array_intersect($this->head->fieldNames(), $active));
    }

    /** @return list<string> Felder der Sammeländerung, die gelöscht oder inaktiv sind (fallen aus der Rücknahme) */
    public function droppedFields(): array
    {
        return array_values(array_diff($this->head->fieldNames(), $this->fields()));
    }

    /**
     * Einträge des Laufs zu den Datensätzen eines Blocks: Schlüssel => alte Werte (erster Eintrag), neue Werte
     * (letzter Eintrag), Ereignisse.
     *
     * @param  list<int|string>  $ids
     * @return array<string, array{old: array<string, mixed>, new: array<string, mixed>, events: list<string>}>
     */
    public function logged(array $ids): array
    {
        $entries = Activity::query()
            ->select(['id', 'subject_id', 'event', 'attribute_changes'])
            ->where('subject_type', DynamicRecord::MORPH_PREFIX.$this->table->name)
            ->whereIn('subject_id', $ids)
            ->where('properties->run', $this->head->run)
            ->orderBy('id')
            ->get();
        $logged = [];

        foreach ($entries as $entry) {
            $key = (string) $entry->subject_id;
            $changes = collect($entry->attribute_changes ?? []);
            $logged[$key] ??= ['old' => [], 'new' => [], 'events' => []];
            $logged[$key]['old'] += (array) $changes->get('old', []);
            $logged[$key]['new'] = [...$logged[$key]['new'], ...(array) $changes->get('attributes', [])];
            $logged[$key]['events'][] = (string) $entry->event;
        }

        return $logged;
    }

    /**
     * Einordnung eines Datensatzes und, bei REVERT und Ändern, die alten Werte in der Form der Datenbank.
     *
     * @param  array{old: array<string, mixed>, new: array<string, mixed>, events: list<string>}|null  $logged
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function classify(?DynamicRecord $record, ?array $logged): array
    {
        if ($record === null) {
            return [self::GONE, []];
        }

        return match ($this->head->operation) {
            BulkChangeHead::DELETE => [$logged === null || ! in_array('deleted', $logged['events'], true) ? self::NOT_LOGGED : ($record->trashed() ? self::REVERT : self::ALREADY), []],
            BulkChangeHead::RESTORE => [$logged === null || ! in_array('restored', $logged['events'], true) ? self::NOT_LOGGED : ($record->trashed() ? self::ALREADY : self::REVERT), []],
            default => $this->classifyUpdate($record, $logged),
        };
    }

    /**
     * @param  array{old: array<string, mixed>, new: array<string, mixed>, events: list<string>}|null  $logged
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function classifyUpdate(DynamicRecord $record, ?array $logged): array
    {
        $fields = array_values(array_filter($this->fields(), static fn (string $f): bool => $logged !== null && array_key_exists($f, $logged['new'])));

        if ($fields === []) {
            return [self::NOT_LOGGED, []];
        }

        $current = $record->getAttributes();
        $written = true;
        $reverted = true;
        $old = [];

        foreach ($fields as $field) {
            $before = $logged['old'][$field] ?? null;
            $written = $written && LoggedValue::same($record, $field, $current[$field] ?? null, $logged['new'][$field]);
            $reverted = $reverted && LoggedValue::same($record, $field, $current[$field] ?? null, $before);
            $old[$field] = LoggedValue::raw($record, $field, $before);
        }

        return match (true) {
            $written => [self::REVERT, $old],
            $reverted => [self::ALREADY, []],
            default => [self::CHANGED, []],
        };
    }
}
