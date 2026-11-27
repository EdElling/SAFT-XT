<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\Formula\Evaluator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Art „rechnen“ einer Sammeländerung (BULK_EDIT.md §4, §12): Zahl + − × ÷ und „um %“ mit kaufmännischem Runden auf
 * Stellen oder ein Vielfaches, exakt mit bcmath wie die Formelsprache; Datum ± Tage oder Monate ohne Überlauf am
 * Monatsende. Ein leerer Wert bleibt leer (der Datensatz wird übersprungen).
 */
final class BulkCalc
{
    public const array NUMBER_OPERATORS = ['add', 'sub', 'mul', 'div', 'percent'];

    public const array DATE_OPERATORS = ['add_days', 'sub_days', 'add_months', 'sub_months'];

    public const string ROUND_DIGITS = 'digits';

    public const string ROUND_MULTIPLE = 'multiple';

    /** Höchstens so viele Tage bzw. Monate auf einmal. */
    public const int MAX_DATE_OPERAND = 99999;

    private const int SCALE = 12;

    /** Zahl-Eingabe als Dezimalzahl-Text (Komma oder Punkt), null bei ungültig. */
    public static function number(mixed $value): ?string
    {
        return is_scalar($value) ? Evaluator::decimal(str_replace([',', "'", ' '], ['.', '', ''], trim((string) $value))) : null;
    }

    /**
     * Neue Zahl; null, wenn der bisherige Wert leer ist. $scale = Nachkommastellen des Felds (Ganzzahl 0).
     *
     * @param  string|null  $roundMode  ROUND_DIGITS, ROUND_MULTIPLE oder null (nur auf das Feld)
     */
    public static function calculate(mixed $current, string $operator, string $operand, int $scale, ?string $roundMode = null, ?string $roundValue = null): ?string
    {
        $base = Evaluator::decimal($current);

        if ($base === null) {
            return null;
        }

        $result = match ($operator) {
            'add' => bcadd($base, $operand, self::SCALE),
            'sub' => bcsub($base, $operand, self::SCALE),
            'mul' => bcmul($base, $operand, self::SCALE),
            'div' => bcdiv($base, $operand, self::SCALE),
            'percent' => bcmul($base, bcadd('1', bcdiv($operand, '100', self::SCALE), self::SCALE), self::SCALE),
            default => throw new InvalidArgumentException("Unbekannte Rechenart: {$operator}"),
        };

        if ($roundMode === self::ROUND_DIGITS && $roundValue !== null) {
            $result = (string) Evaluator::round($result, min((int) $roundValue, $scale));
        } elseif ($roundMode === self::ROUND_MULTIPLE && $roundValue !== null) {
            $result = bcmul((string) Evaluator::round(bcdiv($result, $roundValue, self::SCALE), 0), $roundValue, self::SCALE);
        }

        return (string) Evaluator::round($result, $scale);
    }

    /** Neues Datum (Uhrzeit bleibt); null, wenn der bisherige Wert leer ist. 31.01. + 1 Monat = 28./29.02. */
    public static function shift(mixed $current, string $operator, int $amount, bool $withTime): ?string
    {
        if ($current === null || $current === '') {
            return null;
        }

        $date = $current instanceof DateTimeInterface ? CarbonImmutable::instance($current) : CarbonImmutable::parse((string) $current);

        $date = match ($operator) {
            'add_days' => $date->addDays($amount),
            'sub_days' => $date->subDays($amount),
            'add_months' => $date->addMonthsNoOverflow($amount),
            'sub_months' => $date->subMonthsNoOverflow($amount),
            default => throw new InvalidArgumentException("Unbekannte Rechenart: {$operator}"),
        };

        return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    /** Passt das Vielfache in die Nachkommastellen des Felds (0,05 bei 2 Stellen ja, 0,005 nein) und ist es > 0? */
    public static function validMultiple(string $multiple, int $scale): bool
    {
        return bccomp($multiple, '0', self::SCALE) > 0 && bccomp((string) Evaluator::round($multiple, $scale), $multiple, self::SCALE) === 0;
    }
}
