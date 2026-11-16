<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

use App\Plugins\PluginRegistry;
use Closure;
use RoundingMode;
use Throwable;

/**
 * Rechnet einen Formelbaum exakt mit Dezimalarithmetik (bcmath). Leere Werte ergeben ein leeres
 * Ergebnis (außer mit coalesce), Division durch 0 ebenso.
 */
final class Evaluator
{
    /** Nachkommastellen für Zwischenergebnisse; gerundet wird erst auf das Feld. */
    private const int SCALE = 12;

    /**
     * @param  array<string, mixed>  $node
     * @param  Closure(string): mixed  $field  Wert eines Felds desselben Datensatzes
     * @param  (Closure(array<string, mixed>): mixed)|null  $aggregate  Summe & Co. über Kind-Datensätze
     */
    public function evaluate(array $node, Closure $field, ?Closure $aggregate = null): ?string
    {
        return match (true) {
            isset($node['agg']) => $aggregate === null ? null : self::decimal($aggregate($node)),
            isset($node['num']) => (string) $node['num'],
            isset($node['field']) => self::decimal($field((string) $node['field'])),
            isset($node['neg']) => ($v = $this->evaluate($node['neg'], $field, $aggregate)) === null ? null : bcsub('0', $v, self::SCALE),
            isset($node['op']) => $this->operation($node, $field, $aggregate),
            isset($node['call']) => $this->call($node, $field, $aggregate),
            default => null,
        };
    }

    /** Ergebnis auf die Nachkommastellen des Felds runden (kaufmännisch, ab 5 weg von 0). */
    public static function round(?string $value, int $scale): ?string
    {
        return $value === null ? null : bcround($value, $scale, RoundingMode::HalfAwayFromZero);
    }

    /** Wert als Dezimalzahl-Text für bcmath; null bei leer oder nicht numerisch. */
    public static function decimal(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_int($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => rtrim(rtrim(number_format($value, self::SCALE, '.', ''), '0'), '.') ?: '0',
            is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1 => trim($value),
            is_numeric($value) => self::decimal((float) $value),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function operation(array $node, Closure $field, ?Closure $aggregate): ?string
    {
        $left = $this->evaluate($node['left'], $field, $aggregate);
        $right = $this->evaluate($node['right'], $field, $aggregate);

        if ($left === null || $right === null) {
            return null;
        }

        return match ($node['op']) {
            '+' => bcadd($left, $right, self::SCALE),
            '-' => bcsub($left, $right, self::SCALE),
            '*' => bcmul($left, $right, self::SCALE),
            '/' => bccomp($right, '0', self::SCALE) === 0 ? null : bcdiv($left, $right, self::SCALE),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function call(array $node, Closure $field, ?Closure $aggregate): ?string
    {
        $args = $node['args'];

        if ($node['call'] === 'coalesce') {
            foreach ($args as $arg) {
                if (($value = $this->evaluate($arg, $field, $aggregate)) !== null) {
                    return $value;
                }
            }

            return null;
        }

        $values = array_map(fn (array $arg): ?string => $this->evaluate($arg, $field, $aggregate), $args);

        if (in_array(null, $values, true)) {
            return null;
        }

        return match ($node['call']) {
            'round' => bcround($values[0], (int) ($values[1] ?? 0), RoundingMode::HalfAwayFromZero),
            'abs' => ltrim($values[0], '-'),
            'min' => array_reduce(array_slice($values, 1), static fn (string $carry, string $v): string => bccomp($v, $carry, self::SCALE) < 0 ? $v : $carry, $values[0]),
            'max' => array_reduce(array_slice($values, 1), static fn (string $carry, string $v): string => bccomp($v, $carry, self::SCALE) > 0 ? $v : $carry, $values[0]),
            default => $this->plugin((string) $node['call'], $values),
        };
    }

    /**
     * Formelfunktion eines Plugins; fehlt das Plugin oder ruht es, ist das Ergebnis leer.
     *
     * @param  list<string>  $values
     */
    private function plugin(string $name, array $values): ?string
    {
        $function = app(PluginRegistry::class)->formulaFunctions()[$name] ?? null;

        if ($function === null) {
            return null;
        }

        // Ein Fehler im Plugin soll das Speichern nicht verhindern: Ergebnis leer, Fehler ins Log.
        try {
            return self::decimal(($function->evaluate)($values));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
