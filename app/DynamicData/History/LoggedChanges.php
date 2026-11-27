<?php

declare(strict_types=1);

namespace App\DynamicData\History;

use App\DynamicData\CustomTableLookup;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicValueFormatter;
use App\DynamicData\Write\LoggedValue;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Änderungen eines Datensatzes lesbar machen (B-174, HISTORY.md §3): Bezeichnung des Felds statt Spaltenname, Wert im
 * Format der Anwendung, Bezeichnung der Wahl, heutiger Anzeigewert verknüpfter Datensätze („#4 (gelöscht)“, „#4“).
 * Das Protokoll selbst bleibt technisch (Rohwerte, ARCHITECTURE Nr. 28).
 *
 * prime() schlägt die verknüpften Datensätze aller Einträge einer Anzeige gesammelt nach: eine Abfrage je verknüpfter
 * Tabelle. Ohne prime() schlägt rows() die Verknüpfungen des einen Eintrags ebenso gesammelt nach. Scoped im Container.
 */
final class LoggedChanges
{
    /** @var array<string, array<string, DynamicRecord|null>> Zieltabelle => Schlüssel => Datensatz (null = nicht vorhanden) */
    private array $records = [];

    public function __construct(private readonly CustomTableLookup $tables) {}

    /** Eintrag eines Datensatzes einer (noch vorhandenen) Designer- oder angebundenen Tabelle? */
    public function covers(Activity $activity): bool
    {
        return $this->table($activity) !== null;
    }

    /**
     * Verknüpfte Datensätze aller Einträge vorab nachschlagen.
     *
     * @param  iterable<Activity>  $activities
     */
    public function prime(iterable $activities): void
    {
        $wanted = [];

        foreach ($activities as $activity) {
            if (! $activity instanceof Activity || ($table = $this->table($activity)) === null) {
                continue;
            }

            foreach ($this->values($activity) as $name => [$old, $new]) {
                $field = $this->field($table, (string) $name);

                if ($field?->valueType() !== FieldType::Relation || ! is_string($target = $field->optionsArray()['table'] ?? null)) {
                    continue;
                }

                foreach ([$old, $new] as $value) {
                    if (is_int($value) || (is_string($value) && $value !== '')) {
                        $wanted[$target][(string) $value] = true;
                    }
                }
            }
        }

        foreach ($wanted as $target => $keys) {
            $this->load($target, array_keys($keys));
        }
    }

    /**
     * Lesbare Zeilen; null, wenn der Eintrag keinen Datensatz betrifft (dann technisch anzeigen).
     *
     * @return list<array{field: string, old: string, new: string}>|null
     */
    public function rows(Activity $activity): ?array
    {
        $table = $this->table($activity);

        if ($table === null) {
            return null;
        }

        $this->prime([$activity]);
        $model = DynamicRecord::forTable($table);
        $rows = [];

        foreach ($this->values($activity) as $name => [$old, $new, $hasOld, $hasNew]) {
            $field = $this->field($table, (string) $name);
            $format = fn (mixed $value): string => $this->format($model, $field, (string) $name, $value);

            $rows[] = [
                'field' => $field !== null ? (string) $field->label : (string) $name,
                'old' => $hasOld ? $format($old) : '—',
                'new' => $hasNew ? $format($new) : '—',
            ];
        }

        return $rows;
    }

    private function table(Activity $activity): ?CustomTable
    {
        $type = (string) $activity->subject_type;

        return DynamicRecord::isMorphType($type) ? $this->tables->byName(substr($type, strlen(DynamicRecord::MORPH_PREFIX))) : null;
    }

    private function field(CustomTable $table, string $name): ?CustomField
    {
        return $table->fields->first(static fn (CustomField $field): bool => $field->name === $name);
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: bool, 3: bool}> Feld => [alt, neu, alt vorhanden, neu vorhanden] */
    private function values(Activity $activity): array
    {
        /** @var Collection<string, mixed>|null $changes */
        $changes = $activity->attribute_changes;
        $new = (array) ($changes?->get('attributes') ?? []);
        $old = (array) ($changes?->get('old') ?? []);
        $values = [];

        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $name) {
            $values[(string) $name] = [$old[$name] ?? null, $new[$name] ?? null, array_key_exists($name, $old), array_key_exists($name, $new)];
        }

        return $values;
    }

    private function format(DynamicRecord $model, ?CustomField $field, string $name, mixed $value): string
    {
        if ($field === null) {
            // Feld inzwischen entfernt: Wert wie im Protokoll.
            return match (true) {
                $value === null, $value === '' => '∅',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }

        $text = DynamicValueFormatter::raw($field, LoggedValue::raw($model, $name, $value), $this->relationLabel(...));

        return $text === null || $text === '' ? '∅' : $text;
    }

    private function relationLabel(CustomField $field, int|string $key): string
    {
        $options = $field->optionsArray();
        $target = (string) ($options['table'] ?? '');

        if (! array_key_exists((string) $key, $this->records[$target] ?? [])) {
            $this->load($target, [(string) $key]);
        }

        $record = $this->records[$target][(string) $key] ?? null;
        $label = $record === null ? null : RelationDisplay::label($record, $options);

        if ($label === null) {
            return '#'.$key;
        }

        return $record->hasSoftDeleteColumn() && $record->trashed() ? __('activity.deleted_record', ['label' => $label]) : $label;
    }

    /**
     * Datensätze einer Zieltabelle für die Schlüssel holen: eine Abfrage, mit Papierkorb. Angezeigt wird später mit den
     * Anzeigefeldern des jeweiligen Felds.
     *
     * @param  list<string>  $keys
     */
    private function load(string $target, array $keys): void
    {
        $keys = array_values(array_filter($keys, fn (string $key): bool => ! array_key_exists($key, $this->records[$target] ?? [])));

        if ($keys === []) {
            return;
        }

        try {
            $model = DynamicRecord::forTableName($target);
            $query = $model->newQuery();

            if ($model->hasSoftDeleteColumn()) {
                $query->withTrashed();
            }

            $found = $query->whereKey($keys)->get()->keyBy(static fn (DynamicRecord $record): string => (string) $record->getKey());
        } catch (ModelNotFoundException) {
            $found = collect();
        }

        foreach ($keys as $key) {
            $this->records[$target][$key] = $found->get($key);
        }
    }
}
