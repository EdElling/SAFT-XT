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
 * mitgebrachte nur bei Import und Seed übernehmen (Zähler anheben), leere bestehender Datensätze beim Nachnummerieren
 * (Herkunft „numbering“, N2) füllen, mit „Vergeben, wenn“ (N3) erst bei erfüllter Bedingung vergeben, vergebene Nummern nie ändern – gleich, ob die
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
     * „Vergeben, wenn“ (N3): leere Nummern mit Bedingung vergeben, sobald sie zutrifft – beim Anlegen und beim Ändern.
     * Läuft nach RecordSaving (die Bedingung sieht Werte, die ein Plugin setzt) und vor den berechneten Feldern.
     * Bezugsdatum ist der Zeitpunkt der Vergabe bzw. das Datumsfeld in diesem Moment; nur beim Nachnummerieren das
     * Anlegedatum des Datensatzes (wie ohne Bedingung).
     *
     * @param  array<string, array{id: int, options: array<string, mixed>}>  $numbers
     */
    public function applyConditional(DynamicRecord $record, array $numbers, bool $creating, WriteOptions $options): void
    {
        foreach ($numbers as $field => $number) {
            $value = $record->getAttribute($field);

            if (! NumberCondition::has($number['options']) || ! ($number['options']['active'] ?? true) || ($value !== null && $value !== '')) {
                continue;
            }

            if (NumberCondition::holds($record, $number['options'])) {
                $numbering = ! $creating && $options->origin === WriteOptions::NUMBERING;
                $this->assignNext($record, $field, $number, $numbering ? $record->getAttribute('created_at') : null);
            }
        }
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
                } elseif (! $given && $options->origin === WriteOptions::NUMBERING && ! NumberCondition::has($number['options'])) {
                    // Nachnummerieren (N2): leere Nummer eines bestehenden Datensatzes; ohne Datumsfeld zählt sein
                    // Anlegedatum, sonst bekämen alte Datensätze Nummern des laufenden Jahrs.
                    $this->assignNext($record, $field, $number, $record->getAttribute('created_at'));
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

            // Inaktiv, oder mit Bedingung: die vergibt applyConditional() nach RecordSaving.
            if (! ($number['options']['active'] ?? true) || NumberCondition::has($number['options'])) {
                continue;
            }

            $this->assignNext($record, $field, $number, null);
        }
    }

    /**
     * Nächste Nummer ziehen und setzen.
     *
     * @param  array{id: int, options: array<string, mixed>}  $number
     * @param  mixed  $created  Anlegedatum des Datensatzes (Nachnummerieren); null = jetzt
     */
    private function assignNext(DynamicRecord $record, string $field, array $number, mixed $created): void
    {
        $pattern = $this->pattern($number);
        $period = $pattern->period($this->referenceDate($record, $field, $number['options'], $created));

        $record->setAttribute($field, $this->sequences->next(
            $number['id'],
            $pattern,
            $period,
            static fn (string $candidate): bool => $record->newQueryWithoutScopes()->where($field, $candidate)->exists(),
            $this->group($record, $field, $number['options']),
        ));
    }

    /**
     * Kurzzeichen der Gruppe (N4) aus dem Gruppenfeld des Datensatzes zum Zeitpunkt der Vergabe; ohne Gruppenfeld
     * NO_GROUP. Eine spätere Änderung der Gruppe lässt die vergebene Nummer stehen.
     *
     * @param  array<string, mixed>  $options
     */
    private function group(DynamicRecord $record, string $field, array $options): string
    {
        $groupField = trim((string) ($options[NumberPattern::GROUP_FIELD] ?? ''));

        if ($groupField === '') {
            return NumberSequences::NO_GROUP;
        }

        $value = $record->getAttribute($groupField);

        if ($value === null || $value === '') {
            throw new BusinessRuleException(__('numbering.errors.group_missing', ['field' => $field, 'group' => $groupField]), 'dynamic.number_group_missing');
        }

        $code = (string) (((array) ($options[NumberPattern::GROUP_CODES] ?? []))[(string) $value] ?? $value);

        if (! NumberPattern::validCode($code)) {
            throw new BusinessRuleException(__('numbering.errors.group_value', ['field' => $field, 'value' => $value, 'max' => NumberPattern::MAX_CODE]), 'dynamic.number_group_invalid');
        }

        return $code;
    }

    /**
     * Eine vergebene Nummer ist fest. Eine leere darf nur ein Import bzw. Seed füllen (Altdaten); vergeben wird sonst
     * beim Anlegen bzw. – mit Bedingung (N3) – in applyConditional().
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
            // Mit Gruppe (N4) der Zähler des Kurzzeichens in der Nummer, nicht der Gruppe des Datensatzes.
            $this->sequences->raise($number['id'], $parsed['key'], $parsed['number'], $pattern->start, $parsed['group']);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function referenceDate(DynamicRecord $record, string $field, array $options, mixed $created = null): CarbonInterface
    {
        $dateField = (string) ($options[NumberPattern::DATE_FIELD] ?? '');

        if ($dateField === '') {
            return match (true) {
                $created instanceof CarbonInterface => $created,
                $created !== null && $created !== '' => CarbonImmutable::parse((string) $created),
                default => CarbonImmutable::now(),
            };
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
