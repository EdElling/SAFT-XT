<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberConversion;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Arbeit „Leere Nummern vergeben“ (B-135, N2): Datensätze eines Nummernkreises ohne Nummer nachnummerieren, in der
 * Reihenfolge des Bezugsdatums, dann des Anlegedatums und des Schlüssels. Je Datensatz über den RecordWriter mit der
 * Herkunft „numbering“, die als einzige eine leere Nummer eines bestehenden Datensatzes füllen darf.
 *
 * - Jeder Block nimmt die ersten leeren Datensätze; nummerierte fallen heraus, so setzt ein neuer Versuch richtig fort.
 * - Mit „Vergeben, wenn“ (N3) bekommen nur Datensätze eine Nummer, bei denen die Bedingung zutrifft; die übrigen
 *   warten, bleiben leer und werden gezählt. Weil sie in der Menge bleiben, beginnt der nächste Block um ihre Anzahl
 *   versetzt (Reihenfolge eindeutig dank Schlüssel). Wird einer von ihnen während des Laufs anderswo nummeriert,
 *   rückt ein leerer Datensatz unbearbeitet vor die Grenze – er bleibt leer, ein zweiter Lauf nimmt ihn.
 * - Protokoll je Datensatz mit der Laufkennung „run“ (wie später die Sammeländerung, BULK_EDIT.md); keine
 *   Plugin-Ereignisse – Nachnummerieren soll nichts nach aussen auslösen.
 * - Papierkorb ausgenommen. Datensätze ohne Bezugsdatum bleiben leer und werden gezählt; scheitert ein Datensatz an
 *   einer fachlichen Prüfung, wird er übersprungen und gemeldet (anders als die Sammeländerung nicht „alles oder
 *   nichts“: Eine teilweise Nummerierung schadet nicht, ein zweiter Lauf nimmt den Rest).
 *
 * Parameter „field“: Schlüssel des Felds (custom_fields.id).
 */
final readonly class NumberBackfillWork implements WorkType, WritesRecords
{
    public const string KEY = 'number_backfill';

    public const int BLOCK = 200;

    /** Höchstens so viele übersprungene Datensätze, danach bricht die Arbeit ab (sonst wüchse der Zustand ohne Grenze). */
    private const int MAX_SKIPPED = 500;

    public function __construct(private RecordWriter $writer) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.number_backfill');
    }

    public function describe(array $parameters): string
    {
        $field = self::field($parameters);

        return $field === null ? '–' : $field->customTable->name.'.'.$field->name;
    }

    public function prepare(array $parameters): WorkPlan
    {
        $field = self::field($parameters);

        return new WorkPlan(
            ['run' => self::KEY.':'.Str::uuid(), 'assigned' => 0, 'waiting' => 0, 'skipped' => [], 'errors' => []],
            $field === null ? 0 : self::empties($field)->count(),
        );
    }

    public function step(WorkStep $step): StepOutcome
    {
        $state = $step->state;
        $field = self::field($step->parameters);
        $skipped = array_map('intval', (array) ($state['skipped'] ?? []));
        $errors = (array) ($state['errors'] ?? []);
        $assigned = (int) ($state['assigned'] ?? 0);
        $waiting = (int) ($state['waiting'] ?? 0);
        $fieldOptions = $field?->optionsArray() ?? [];
        $records = $field === null ? collect() : self::empties($field)
            ->when($skipped !== [], static fn (Builder $query) => $query->whereKeyNot($skipped))
            ->when($waiting > 0, static fn (Builder $query) => $query->offset($waiting))
            ->limit(self::BLOCK)
            ->get();

        $options = new WriteOptions(origin: WriteOptions::NUMBERING, pluginEvents: false, run: (string) $state['run']);

        foreach ($records as $record) {
            // „Vergeben, wenn“: ohne erfüllte Bedingung bleibt die Nummer leer (gezählt, nicht gespeichert).
            if (! NumberCondition::holds($record, $fieldOptions)) {
                $waiting++;

                continue;
            }

            try {
                // Eigener Sicherungspunkt je Datensatz: Scheitert er, rollt auch sein gezogener Zählerstand zurück.
                $record->getConnection()->transaction(fn () => $this->writer->save($record, $options));
                $assigned++;
            } catch (BusinessRuleException $e) {
                $skipped[] = (int) $record->getKey();

                if (count($errors) < NumberConversion::EXAMPLES) {
                    $errors[] = '#'.$record->getKey().': '.$e->getMessage();
                }

                if (count($skipped) > self::MAX_SKIPPED) {
                    throw new BusinessRuleException(__('work.number_backfill.too_many_errors', ['count' => count($skipped), 'errors' => implode(' · ', $errors)]), 'work.number_backfill_errors');
                }
            }
        }

        $state = [...$state, 'assigned' => $assigned, 'waiting' => $waiting, 'skipped' => $skipped, 'errors' => $errors];

        if ($field !== null && $records->count() === self::BLOCK) {
            return StepOutcome::continue($state, $records->count());
        }

        $withoutDate = $field === null ? 0 : self::withoutDate($field);
        $message = trans_choice('work.number_backfill.done', $assigned, ['count' => $assigned]);

        if ($waiting > 0) {
            $message .= ' '.trans_choice('work.number_backfill.waiting', $waiting, ['count' => $waiting, 'condition' => (string) ($fieldOptions[NumberCondition::OPTION] ?? '')]);
        }

        if ($withoutDate > 0) {
            $message .= ' '.trans_choice('work.number_backfill.without_date', $withoutDate, ['count' => $withoutDate]);
        }

        if ($skipped !== []) {
            $message .= ' '.trans_choice('work.number_backfill.skipped', count($skipped), ['count' => count($skipped), 'errors' => implode(' · ', $errors)]);
        }

        return StepOutcome::finished($message, ['assigned' => $assigned, 'waiting' => $waiting, 'skipped' => count($skipped), 'without_date' => $withoutDate, 'run' => $state['run']], $records->count());
    }

    /**
     * Nicht gelöschte Datensätze ohne Nummer, die ein Bezugsdatum haben, in der Reihenfolge der Vergabe.
     *
     * @return Builder<DynamicRecord>
     */
    public static function empties(CustomField $field): Builder
    {
        $column = $field->name;
        $date = self::dateField($field);

        return DynamicRecord::forTable($field->customTable)->newQuery()
            ->where(static fn (Builder $query) => $query->whereNull($column)->orWhere($column, ''))
            ->when($date !== null, static fn (Builder $query) => $query->whereNotNull($date)->orderBy($date))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** Leere Nummern, die nachnummeriert werden können (Aktion „Jetzt nummerieren“). */
    public static function pending(CustomField $field): int
    {
        return $field->type === FieldType::Number && $field->is_active ? self::empties($field)->count() : 0;
    }

    private static function withoutDate(CustomField $field): int
    {
        $date = self::dateField($field);

        return $date === null ? 0 : DynamicRecord::forTable($field->customTable)->newQuery()
            ->where(static fn (Builder $query) => $query->whereNull($field->name)->orWhere($field->name, ''))
            ->whereNull($date)
            ->count();
    }

    private static function dateField(CustomField $field): ?string
    {
        $date = (string) ($field->optionsArray()[NumberPattern::DATE_FIELD] ?? '');

        return $date === '' ? null : $date;
    }

    /** @param  array<string, mixed>  $parameters */
    private static function field(array $parameters): ?CustomField
    {
        $field = CustomField::query()->with('customTable')->find((int) ($parameters['field'] ?? 0));

        return $field !== null && $field->type === FieldType::Number && $field->is_active ? $field : null;
    }
}
