<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Wertet Regeln typgerecht aus (Zahl, Datum, Text). Bewusst unabhängig von Laravels Validierung,
 * damit später auch berechnete Felder dieselbe Auswertung von Operanden nutzen können.
 */
final class RuleEvaluator
{
    /**
     * @param  Closure(string): mixed  $value  Wert eines Felds desselben Datensatzes
     * @return bool|null null = nicht anwendbar (eine Seite leer bei „nur wenn gefüllt“)
     */
    public function passes(TableRule $rule, Closure $value): ?bool
    {
        $left = $this->operand($rule->left, $value);
        $right = $this->operand($rule->right, $value);

        if (self::blank($left) || self::blank($right)) {
            return $rule->whenFilled ? null : false;
        }

        return self::compare($left, $rule->operator, $right);
    }

    /**
     * @param  Closure(string): mixed  $value
     */
    public function operand(Operand $operand, Closure $value): mixed
    {
        return $operand->isField() ? $value((string) $operand->fieldName()) : $operand->value;
    }

    public static function compare(mixed $left, string $operator, mixed $right): bool
    {
        [$a, $b] = self::comparable($left, $right);
        $order = $a <=> $b;

        return match ($operator) {
            '=' => $order === 0,
            '<>' => $order !== 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '>=' => $order >= 0,
            default => false,
        };
    }

    /**
     * Zahlen als Zahlen, Datumswerte als Zeitpunkte, sonst Text.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private static function comparable(mixed $left, mixed $right): array
    {
        if (is_numeric($left) && is_numeric($right)) {
            return [(float) $left, (float) $right];
        }

        $a = self::timestamp($left);
        $b = self::timestamp($right);

        if ($a !== null && $b !== null) {
            return [$a, $b];
        }

        return [(string) $left, (string) $right];
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
