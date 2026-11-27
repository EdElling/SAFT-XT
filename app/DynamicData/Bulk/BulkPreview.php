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
 * Vorschau einer Sammeländerung (BULK_EDIT.md §5), ohne zu schreiben: Anzahl, die ersten Beispiele vorher → nachher und
 * Hinweise zu Nummernkreisen. Ob ein Datensatz die Prüfungen besteht, zeigt erst die Prüfphase der Arbeit.
 */
final readonly class BulkPreview
{
    public const int EXAMPLES = 10;

    /** Schlüssel je Abfrage (PostgreSQL erlaubt höchstens 65'535 Parameter). */
    public const int CHUNK = 1000;

    /**
     * @param  list<array{record: DynamicRecord, before: array<string, mixed>, after: array<string, mixed>}>  $examples
     * @param  list<string>  $hints
     */
    public function __construct(
        public int $count,
        public array $examples,
        public array $hints,
    ) {}

    /** @param  list<int|string>  $ids */
    public static function for(BulkChange $change, array $ids): self
    {
        $examples = [];
        $fields = $change->fieldNames();

        $first = $ids;
        sort($first);

        foreach (self::query($change)->whereKey(array_slice($first, 0, self::EXAMPLES))->orderBy('id')->get() as $record) {
            $before = array_combine($fields, array_map(static fn (string $name): mixed => $record->getAttribute($name), $fields));
            $change->apply($record);
            $after = array_combine($fields, array_map(static fn (string $name): mixed => $record->getAttribute($name), $fields));
            $examples[] = ['record' => $record, 'before' => $before, 'after' => $after];
        }

        return new self(count($ids), $examples, self::hints($change, $ids));
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
