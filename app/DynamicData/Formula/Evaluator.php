<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

use App\Plugins\PluginRegistry;
use BackedEnum;
use Closure;
use DateTimeInterface;
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
            // Text hat nur in Vergleichen einen Wert (FormulaAnalyzer erlaubt ihn nur dort).
            isset($node['str']) => null,
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
        if (in_array($node['op'], Parser::COMPARISONS, true)) {
            return $this->compare($node, $field, $aggregate);
        }

        if ($node['op'] === 'and' || $node['op'] === 'or') {
            $left = self::truthy($this->evaluate($node['left'], $field, $aggregate));

            // Wie gewohnt kurz geschlossen: Die rechte Seite zählt nur, wenn die linke nicht schon entscheidet.
            $result = $node['op'] === 'and'
                ? $left && self::truthy($this->evaluate($node['right'], $field, $aggregate))
                : $left || self::truthy($this->evaluate($node['right'], $field, $aggregate));

            return $result ? '1' : '0';
        }

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
     * Vergleich: 1 (erfüllt) oder 0. Zahlen exakt; sonst als Text, z. B. der gespeicherte Wert eines Auswahlfelds.
     * Ist eine Seite leer, ist der Vergleich nicht erfüllt – auch bei „<>“.
     *
     * @param  array<string, mixed>  $node
     */
    private function compare(array $node, Closure $field, ?Closure $aggregate): string
    {
        $left = $this->operand($node['left'], $field, $aggregate);
        $right = $this->operand($node['right'], $field, $aggregate);

        if ($left === null || $right === null) {
            return '0';
        }

        $left = (string) $left;
        $right = (string) $right;
        $numbers = self::decimal($left) !== null && self::decimal($right) !== null;
        $order = $numbers ? bccomp($left, $right, self::SCALE) : strcmp($left, $right) <=> 0;

        $result = match ($node['op']) {
            '=' => $order === 0,
            '<>' => $order !== 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '>=' => $order >= 0,
            default => false,
        };

        return $result ? '1' : '0';
    }

    /**
     * Seite eines Vergleichs: Text, der Wert eines Felds (auch Text, Auswahl, Datum) oder ein gerechneter Wert.
     *
     * @param  array<string, mixed>  $node
     */
    private function operand(array $node, Closure $field, ?Closure $aggregate): ?string
    {
        if (isset($node['str'])) {
            return (string) $node['str'];
        }

        if (! isset($node['field'])) {
            return $this->evaluate($node, $field, $aggregate);
        }

        $value = $field((string) $node['field']);

        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => self::decimal($value) ?? (string) $value,
            default => null,
        };
    }

    /** Bedingung erfüllt: ein Wert ungleich 0 (Vergleiche liefern 1 oder 0, Ja/Nein-Felder ebenso). */
    private static function truthy(?string $value): bool
    {
        return $value !== null && bccomp($value, '0', self::SCALE) !== 0;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function call(array $node, Closure $field, ?Closure $aggregate): ?string
    {
        $args = $node['args'];

        // contains/startswith/endswith(text, teil): ohne Gross-/Kleinschreibung; ist eine Seite leer, nicht erfüllt.
        if (in_array($node['call'], FormulaAnalyzer::TEXT_FUNCTIONS, true)) {
            $text = $this->operand($args[0], $field, $aggregate);
            $part = $this->operand($args[1], $field, $aggregate);

            if ($text === null || $part === null) {
                return '0';
            }

            [$text, $part] = [mb_strtolower($text), mb_strtolower($part)];

            return match ($node['call']) {
                'contains' => str_contains($text, $part),
                'startswith' => str_starts_with($text, $part),
                default => str_ends_with($text, $part),
            } ? '1' : '0';
        }

        // if(bedingung, dann[, sonst]): nur der gewählte Zweig wird gerechnet; ohne „sonst“ ist das Ergebnis leer.
        if ($node['call'] === 'if') {
            if (self::truthy($this->evaluate($args[0], $field, $aggregate))) {
                return $this->evaluate($args[1], $field, $aggregate);
            }

            return isset($args[2]) ? $this->evaluate($args[2], $field, $aggregate) : null;
        }

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
