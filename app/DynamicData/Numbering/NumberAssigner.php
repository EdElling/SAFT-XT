<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\DynamicRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Nummernkreise im Schreibweg (B-135, Teil von WritePhases::beforeSave): beim Anlegen leere Nummern vergeben,
 * mitgebrachte nur bei Import und Seed übernehmen (Zähler anheben), vergebene Nummern nie ändern – gleich, ob die
 * Änderung aus der Oberfläche, einem Plugin, einer Regel oder sonstwoher kommt.
 */
final class NumberAssigner
{
    /** Herkünfte, die Nummern mitbringen dürfen (Altdaten, Demo-Daten). */
    private const array KEEPS_GIVEN = [WriteOptions::IMPORT, WriteOptions::SEED];

    public function __construct(private readonly NumberSequences $sequences) {}

    /**
     * Vergibt bzw. prüft und gibt den Stand der Nummern danach zurück – für assertKept() nach den Plugin-Ereignissen.
     *
     * @param  array<string, array{id: int, options: array<string, mixed>}>  $numbers
     * @return array<string, mixed>
     */
    public function apply(DynamicRecord $record, array $numbers, bool $creating, WriteOptions $options): array
    {
        $this->assign($record, $numbers, $creating, $options);

        return array_map(static fn (string $field): mixed => $record->getAttribute($field), array_combine(array_keys($numbers), array_keys($numbers)));
    }

    /**
     * Nach RecordSaving: Ein Listener darf eine Nummer weder setzen noch ändern.
     *
     * @param  array<string, mixed>  $before  Rückgabe von apply()
     */
    public function assertKept(DynamicRecord $record, array $before): void
    {
        foreach ($before as $field => $value) {
            if ($record->getAttribute($field) !== $value) {
                throw new BusinessRuleException(__('numbering.errors.immutable', ['field' => $field]), 'dynamic.number_immutable');
            }
        }
    }

    /**
     * @param  array<string, array{id: int, options: array<string, mixed>}>  $numbers
     */
    private function assign(DynamicRecord $record, array $numbers, bool $creating, WriteOptions $options): void
    {
        foreach ($numbers as $field => $number) {
            $value = $record->getAttribute($field);
            $given = $value !== null && $value !== '';

            if (! $creating) {
                if ($record->isDirty($field)) {
                    $this->assertChangeAllowed($record, $field, $given, $number, $options);
                }

                continue;
            }

            if ($given) {
                if (! in_array($options->origin, self::KEEPS_GIVEN, true)) {
                    throw new BusinessRuleException(__('numbering.errors.given', ['field' => $field]), 'dynamic.number_given');
                }

                $this->raiseFor((string) $value, $number);

                continue;
            }

            if ($value === '') {
                $record->setAttribute($field, null);
            }

            if (! ($number['options']['active'] ?? true)) {
                continue;
            }

            $pattern = $this->pattern($number);
            $period = $pattern->period($this->referenceDate($record, $field, $number['options']));

            $record->setAttribute($field, $this->sequences->next(
                $number['id'],
                $pattern,
                $period,
                static fn (string $candidate): bool => $record->newQueryWithoutScopes()->where($field, $candidate)->exists(),
            ));
        }
    }

    /**
     * Eine vergebene Nummer ist fest. Eine leere darf nur ein Import bzw. Seed füllen (Altdaten); vergeben wird sonst
     * nur beim Anlegen (N3: bei einer Bedingung).
     *
     * @param  array{id: int, options: array<string, mixed>}  $number
     */
    private function assertChangeAllowed(DynamicRecord $record, string $field, bool $given, array $number, WriteOptions $options): void
    {
        $original = $record->getOriginal($field);

        if (($original === null || $original === '') && $given && in_array($options->origin, self::KEEPS_GIVEN, true)) {
            $this->raiseFor((string) $record->getAttribute($field), $number);

            return;
        }

        throw new BusinessRuleException(__('numbering.errors.immutable', ['field' => $field]), 'dynamic.number_immutable');
    }

    /**
     * Passt eine mitgebrachte Nummer zum Muster, wird der Zähler ihrer Periode mindestens auf sie angehoben – spätere
     * Nummern kollidieren dann nicht. Fremde Werte bleiben, wie sie sind (der Import meldet sie).
     *
     * @param  array{id: int, options: array<string, mixed>}  $number
     */
    private function raiseFor(string $value, array $number): void
    {
        $pattern = $this->pattern($number);
        $parsed = $pattern->parse($value);

        if ($parsed !== null) {
            $this->sequences->raise($number['id'], $parsed['key'], $parsed['number'], $pattern->start);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function referenceDate(DynamicRecord $record, string $field, array $options): CarbonInterface
    {
        $dateField = (string) ($options[NumberPattern::DATE_FIELD] ?? '');

        if ($dateField === '') {
            return CarbonImmutable::now();
        }

        $date = $record->getAttribute($dateField);

        if ($date === null || $date === '') {
            throw new BusinessRuleException(__('numbering.errors.date_missing', ['field' => $field, 'date' => $dateField]), 'dynamic.number_date_missing');
        }

        return $date instanceof CarbonInterface ? $date : CarbonImmutable::parse((string) $date);
    }

    /**
     * @param  array{id: int, options: array<string, mixed>}  $number
     */
    private function pattern(array $number): NumberPattern
    {
        return NumberPattern::fromOptions($number['options']);
    }
}
