<?php

declare(strict_types=1);

namespace App\DynamicData\History;

use App\DynamicData\DataAccess;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\TableDefinition;
use App\DynamicData\Ui\DynamicValueFormatter;
use App\DynamicData\Write\LoggedValue;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Rücknahme eines einzelnen Eintrags „Geändert“ im Verlauf eines Datensatzes (B-173, HISTORY.md §4). Ordnet jedes Feld
 * des Eintrags ein und liefert die alten Werte zum Zurückschreiben. Geschrieben wird als gewöhnliches Bearbeiten
 * (DynamicRecordActions::undoHistoryEntry(), RecordWriter mit WriteOptions::reverting()).
 *
 * - wählbar: Häkchen an
 * - seither geändert (heutiger Wert ≠ neuer Wert des Eintrags): wählbar, Häkchen aus
 * - schon zurück (heutiger Wert = alter Wert): grau
 * - nicht wählbar (berechnet, Summe, Nummernkreis, Datei, nur lesbar, Schlüssel, entfernt): grau mit Grund
 *
 * Verglichen wird mit LoggedValue::same(), damit Datums- und Dezimalwerte nicht scheinbar abweichen.
 */
final class HistoryUndo
{
    public const string SELECT = 'select';

    public const string CHANGED = 'changed';

    public const string ALREADY = 'already';

    public const string FIXED = 'fixed';

    /** @var list<array{name: string, label: string, status: string, reason: string|null, now: string, back: string}>|null */
    private ?array $fields = null;

    /** @var array<string, array{0: string, 1: string|null}>|null */
    private ?array $statuses = null;

    /** @param  DynamicRecord  $record  der heutige Stand des Datensatzes */
    public function __construct(public readonly Activity $entry, public readonly CustomTable $table, public readonly DynamicRecord $record) {}

    /** Eintrag des Verlaufs dieses Datensatzes; null, wenn er nicht zum Datensatz gehört. */
    public static function of(int $entry, CustomTable $table, DynamicRecord $record): ?self
    {
        $found = RecordHistory::query($record)->whereKey($entry)->first();

        return $found === null ? null : new self($found, $table, $record);
    }

    /** Darf die angemeldete Person Einträge dieser Tabelle zurücknehmen (Recht „ändern“, Tabelle erlaubt Bearbeiten)? */
    public static function allowed(CustomTable $table, DynamicRecord $record): bool
    {
        return $table->supports('update') && DataAccess::current($table, 'update') && ! ($record->hasSoftDeleteColumn() && $record->trashed());
    }

    /** Symbol „Rückgängig“ zeigen: Eintrag „Geändert“ mit mindestens einem Feld, das sich zurücknehmen lässt. */
    public function offered(): bool
    {
        return $this->entry->event === 'updated'
            && collect($this->statuses())->contains(static fn (array $status): bool => in_array($status[0], [self::SELECT, self::CHANGED], true));
    }

    /**
     * Felder des Eintrags in der Reihenfolge des Formulars, entfernte am Ende; mit heutigem und altem Wert in Anzeigeform.
     *
     * @return list<array{name: string, label: string, status: string, reason: string|null, now: string, back: string}>
     */
    public function fields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $old = (array) $this->changes()->get('old', []);
        $raw = $this->record->getAttributes();
        $fields = $this->table->fields->keyBy('name');
        $rows = [];

        foreach ($this->statuses() as $name => [$status, $reason]) {
            /** @var CustomField|null $field */
            $field = $fields->get($name);
            $rows[] = [
                'name' => $name,
                'label' => $field !== null ? (string) $field->label : $name,
                'status' => $status,
                'reason' => $reason,
                'now' => $this->display($field, $raw[$name] ?? null),
                'back' => $this->display($field, LoggedValue::raw($this->record, $name, $old[$name] ?? null)),
            ];
        }

        return $this->fields = $rows;
    }

    /**
     * Einordnung je Feld ohne Anzeigewerte (das Symbol im Verlauf fragt sie für jeden Eintrag ab).
     *
     * @return array<string, array{0: string, 1: string|null}> Feld => [Einordnung, Grund]
     */
    private function statuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        $old = (array) $this->changes()->get('old', []);
        $new = (array) $this->changes()->get('attributes', []);
        $raw = $this->record->getAttributes();
        $fields = $this->table->fields->keyBy('name');
        $statuses = [];

        foreach (array_keys($new) as $name) {
            $name = (string) $name;
            $reason = $this->reason($fields->get($name));
            $current = $raw[$name] ?? null;

            $statuses[$name] = [match (true) {
                $reason !== null => self::FIXED,
                LoggedValue::same($this->record, $name, $current, $old[$name] ?? null) => self::ALREADY,
                ! LoggedValue::same($this->record, $name, $current, $new[$name]) => self::CHANGED,
                default => self::SELECT,
            }, $reason];
        }

        // Reihenfolge des Formulars, entfernte Felder am Ende (MySQL ordnet die Schlüssel im JSON selbst).
        $order = static fn (string $name): int => $fields->has($name) ? $fields->get($name)->sort_order : PHP_INT_MAX;
        uksort($statuses, static fn (string $a, string $b): int => [$order($a), $a] <=> [$order($b), $b]);

        return $this->statuses = $statuses;
    }

    /** @return list<string> Felder, deren Häkchen beim Öffnen gesetzt ist */
    public function preselected(): array
    {
        return array_keys(array_filter($this->statuses(), static fn (array $status): bool => $status[0] === self::SELECT));
    }

    /**
     * Alte Werte der gewählten Felder in der Form der Datenbank; nicht wählbare und schon zurückgesetzte fallen weg.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public function values(array $names): array
    {
        $old = (array) $this->changes()->get('old', []);
        $values = [];

        foreach ($this->statuses() as $name => [$status]) {
            if (in_array($name, $names, true) && in_array($status, [self::SELECT, self::CHANGED], true)) {
                $values[$name] = LoggedValue::raw($this->record, $name, $old[$name] ?? null);
            }
        }

        return $values;
    }

    /** @return Collection<string, mixed> */
    private function changes(): Collection
    {
        return collect($this->entry->attribute_changes ?? []);
    }

    /** Warum ein Feld nicht wählbar ist; null = wählbar. */
    private function reason(?CustomField $field): ?string
    {
        if ($field === null || ! $field->is_active) {
            return 'removed';
        }

        return match (true) {
            $field->type === FieldType::Computed => 'computed',
            $field->type === FieldType::Number => 'number',
            $field->type === FieldType::File => 'file',
            $this->table->isExternal() && in_array($field->name, $this->table->keyColumns(), true) => 'key',
            $field->is_read_only, ! $field->show_in_form, $this->table->isExternal() && ! TableDefinition::isEditable($field) => 'read_only',
            default => null,
        };
    }

    private function display(?CustomField $field, mixed $value): string
    {
        if ($field === null) {
            return $value === null || $value === '' ? '∅' : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        $text = DynamicValueFormatter::raw($field, $value);

        return $text === null || $text === '' ? '∅' : $text;
    }
}
