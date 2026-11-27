<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vorschau einer Sammeländerung (BULK_EDIT.md §5, §12 Frage 13), ohne zu schreiben: Anzahl, bis zu 10 Beispiele, die
 * sich ändern, vorher → nachher, Zählungen und Hinweise zu Nummernkreisen. Gerechnet wird über die ersten SCAN
 * ausgewählten Datensätze in Schlüsselreihenfolge; darüber sind die Zählungen ein Ausschnitt, die genauen Zahlen
 * liefert „Nur prüfen“. Fehler der Rechnung (Division durch 0) stehen beim Beispiel; ob ein Datensatz die Prüfungen
 * besteht (Pflicht, Länge, Plugins), zeigt erst die Prüfphase der Arbeit.
 */
final readonly class BulkPreview
{
    public const int EXAMPLES = 10;

    /** So viele ausgewählte Datensätze rechnet die Vorschau durch. */
    public const int SCAN = 500;

    /** Schlüssel je Abfrage (PostgreSQL erlaubt höchstens 65'535 Parameter). */
    public const int CHUNK = 1000;

    /**
     * @param  list<array{record: DynamicRecord, original: DynamicRecord, before: array<string, mixed>, after: array<string, mixed>, errors: array<string, string>}>  $examples
     * @param  list<string>  $hints
     * @param  array{changing: int, unchanged: int, skipped: int, failed: int}  $counts  über die ersten SCAN
     */
    public function __construct(
        public int $count,
        public array $examples,
        public array $hints,
        public array $counts = ['changing' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0],
        public bool $partial = false,
    ) {}

    /**
     * Setzt voraus, dass $change->validate() bestanden hat.
     *
     * @param  list<int|string>  $ids
     */
    public static function for(BulkChange $change, array $ids): self
    {
        $examples = [];
        $counts = ['changing' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $fields = $change->fieldNames();
        $values = static fn (DynamicRecord $record): array => array_combine($fields, array_map(static fn (string $name): mixed => $record->getAttribute($name), $fields));

        $first = $ids;
        sort($first);

        foreach (self::query($change)->whereKey(array_slice($first, 0, self::SCAN))->orderBy('id')->get() as $record) {
            $original = clone $record;
            $resolved = $change->resolve($record);

            if ($resolved['errors'] !== []) {
                $counts['failed']++;
            } elseif ($change->apply($record, $resolved['values'])) {
                $counts['changing']++;
            } else {
                $counts[$resolved['skipped'] === [] ? 'unchanged' : 'skipped']++;

                continue;
            }

            if (count($examples) < self::EXAMPLES) {
                $examples[] = ['record' => $record, 'original' => $original, 'before' => $values($original), 'after' => $values($record), 'errors' => $resolved['errors']];
            }
        }

        return new self(count($ids), $examples, self::hints($change, $ids), $counts, count($ids) > self::SCAN);
    }

    /**
     * Nummernkreise: Gruppe und Bezugsdatum lassen vergebene Nummern stehen (N4); Felder aus „Vergeben, wenn“ (N3)
     * können beim Speichern Nummern vergeben.
     *
     * @param  list<int|string>  $ids
     * @return list<string>
     */
    public static function hints(BulkChange $change, array $ids): array
    {
        $hints = [];
        $changed = $change->fieldNames();
        $labels = $change->table->fields->keyBy('name');

        foreach ($change->table->fields as $number) {
            /** @var CustomField $number */
            if ($number->type !== FieldType::Number || ! $number->is_active) {
                continue;
            }

            $options = $number->optionsArray();
            $fixed = array_values(array_intersect($changed, array_filter([
                (string) ($options[NumberPattern::GROUP_FIELD] ?? ''),
                (string) ($options[NumberPattern::DATE_FIELD] ?? ''),
            ])));

            if ($fixed !== []) {
                $numbered = self::countNumbered($change, $number, $ids);

                if ($numbered > 0) {
                    $hints[] = trans_choice('data.bulk.hints.number_kept', $numbered, [
                        'count' => $numbered,
                        'fields' => implode(', ', array_map(static fn (string $name): string => (string) ($labels[$name]->label ?? $name), $fixed)),
                        'number' => $number->label,
                    ]);
                }
            }

            $conditional = array_values(array_intersect($changed, NumberCondition::fields($options)));

            if ($conditional !== []) {
                $hints[] = __('data.bulk.hints.number_assigned', [
                    'fields' => implode(', ', array_map(static fn (string $name): string => (string) ($labels[$name]->label ?? $name), $conditional)),
                    'number' => $number->label,
                ]);
            }
        }

        return $hints;
    }

    /** @param  list<int|string>  $ids */
    private static function countNumbered(BulkChange $change, CustomField $number, array $ids): int
    {
        $count = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $count += self::query($change)->whereKey($chunk)->whereNotNull($number->name)->where($number->name, '<>', '')->count();
        }

        return $count;
    }

    /** @return Builder<DynamicRecord> */
    private static function query(BulkChange $change): Builder
    {
        return DynamicRecord::forTable($change->table)->newQuery();
    }
}
