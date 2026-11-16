<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Parser;
use App\Reports\Dialects\QueryDialect;

/**
 * Formel in einer Auswertung (berechneter Wert oder berechnete Listenspalte): derselbe Formelbaum wie bei den
 * berechneten Feldern (Parser), aber die Felder sind Schlüssel der Auswertung (mit Pfad über Verknüpfungen).
 *
 * Erlaubt: Zahlen, Felder, + − * /, Vorzeichen, round(x, Stellen), abs, min, max, coalesce. Keine Zusammenfassungen
 * und keine Pfade innerhalb der Formel. In der Abfrage wird der geprüfte Baum zu Standard-SQL; leere Werte und
 * Division durch 0 ergeben leer – wie bei den berechneten Feldern.
 */
final class ReportExpression
{
    public const int MAX_NODES = 100;

    public const int MAX_SCALE = 10;

    /**
     * Gründe, warum ein Ausdruck nicht passt (leer = gültig).
     *
     * @param  array<string, mixed>  $node
     * @param  callable(string): (bool|null)  $numeric  Feldschlüssel → Zahlenfeld? (null = unbekannt)
     * @param  callable(string): string  $label  Feldschlüssel → Bezeichnung für Meldungen
     * @return list<string>
     */
    public static function problems(array $node, callable $numeric, callable $label): array
    {
        $errors = [];
        $count = 0;
        self::walk($node, $numeric, $label, $errors, $count);

        if ($count > self::MAX_NODES) {
            $errors[] = __('reports.errors.expression_too_long', ['max' => self::MAX_NODES]);
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string> verwendete Feldschlüssel
     */
    public static function fieldKeys(array $node): array
    {
        $keys = [];
        $walk = function (array $n) use (&$walk, &$keys): void {
            if (isset($n['field'])) {
                $keys[] = (string) $n['field'];
            }

            foreach (['left', 'right', 'neg'] as $child) {
                if (isset($n[$child]) && is_array($n[$child])) {
                    $walk($n[$child]);
                }
            }

            foreach ((array) ($n['args'] ?? []) as $arg) {
                if (is_array($arg)) {
                    $walk($arg);
                }
            }
        };
        $walk($node);

        return array_values(array_unique($keys));
    }

    /**
     * Lesbarer Text: „[Menge] × [Artikel › Verkaufspreis]“.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(string): string  $label
     */
    public static function text(array $node, callable $label): string
    {
        return match (true) {
            isset($node['op']) => self::wrap($node['left'], $label, $node['op'], false).' '.self::symbol((string) $node['op']).' '.self::wrap($node['right'], $label, $node['op'], true),
            isset($node['num']) => (string) $node['num'],
            isset($node['field']) => '['.$label((string) $node['field']).']',
            isset($node['neg']) => '−'.(isset($node['neg']['op']) ? '('.self::text($node['neg'], $label).')' : self::text($node['neg'], $label)),
            isset($node['call']) => $node['call'].'('.implode('; ', array_map(fn (array $a): string => self::text($a, $label), $node['args'])).')',
            default => '',
        };
    }

    /**
     * Text mit Klammernamen → Formelbaum mit Feldschlüsseln. Unbekannte Namen ergeben eine FormulaException.
     *
     * @param  array<string, string>  $keys  Bezeichnung (ohne Klammern) → Feldschlüssel
     * @return array<string, mixed>
     */
    public static function parse(string $text, array $keys): array
    {
        $placeholders = [];
        $normalized = preg_replace_callback('/\[([^\]]*)\]/u', function (array $m) use ($keys, &$placeholders): string {
            $name = trim($m[1]);

            if (! isset($keys[$name])) {
                throw new InvalidReportException([__('reports.errors.expression_unknown_field', ['label' => $name])]);
            }

            $placeholder = 'x'.count($placeholders);
            $placeholders[$placeholder] = $keys[$name];

            return $placeholder;
        }, str_replace(['×', '÷', '−', ';'], ['*', '/', '-', ','], $text)) ?? '';

        try {
            $tree = app(Parser::class)->parse($normalized);
        } catch (FormulaException $e) {
            // Die Position bezieht sich auf den umgeschriebenen Text (Platzhalter statt Klammernamen): weglassen.
            $prefix = preg_quote(__('tables.formula.errors.at', ['position' => 'QQPOSQQ', 'message' => 'QQMSGQQ']), '/');
            $pattern = '/^'.str_replace(['QQPOSQQ', 'QQMSGQQ'], ['\\d+', '(.*)'], $prefix).'$/us';

            throw new InvalidReportException([preg_match($pattern, $e->getMessage(), $m) === 1 ? $m[1] : $e->getMessage()]);
        }

        return self::rename($tree, fn (string $name): string => $placeholders[$name] ?? throw new InvalidReportException([__('reports.errors.expression_unknown_field', ['label' => $name])]));
    }

    /**
     * Standard-SQL für die Abfrage; Spalten werden über $column (gekapselt) eingesetzt, Zahlen sind geprüft.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(string): string  $column  Feldschlüssel → gekapselte Spalte
     */
    public static function sql(array $node, callable $column, QueryDialect $dialect): string
    {
        $sql = fn (array $n): string => self::sql($n, $column, $dialect);

        return match (true) {
            isset($node['num']) => self::number($node['num']),
            isset($node['field']) => $dialect->decimal($column((string) $node['field'])),
            isset($node['neg']) => '(-'.$sql($node['neg']).')',
            isset($node['op']) && $node['op'] === '/' => '('.$sql($node['left']).' / NULLIF('.$sql($node['right']).', 0))',
            isset($node['op']) => '('.$sql($node['left']).' '.self::operator((string) $node['op']).' '.$sql($node['right']).')',
            isset($node['call']) => self::call((string) $node['call'], array_map($sql, $node['args']), $node['args']),
            default => throw new InvalidReportException([__('reports.errors.expression_invalid')]),
        };
    }

    /**
     * Feldnamen im Baum umsetzen (z. B. Feldreferenz des Editors ↔ Feldschlüssel der Definition).
     *
     * @param  array<string, mixed>  $node
     * @param  callable(string): string  $map
     * @return array<string, mixed>
     */
    public static function mapFields(array $node, callable $map): array
    {
        return self::rename($node, $map);
    }

    // --- intern ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): (bool|null)  $numeric
     * @param  callable(string): string  $label
     * @param  list<string>  $errors
     */
    private static function walk(array $node, callable $numeric, callable $label, array &$errors, int &$count): void
    {
        $count++;

        // Pfade (tabelle.feld) und Zusammenfassungen über Kind-Datensätze: nicht in Auswertungen.
        if (isset($node['path']) || isset($node['agg'])) {
            $errors[] = __('reports.errors.expression_invalid');

            return;
        }

        if (isset($node['num'])) {
            if (preg_match('/^\d+(\.\d+)?$/', (string) $node['num']) !== 1) {
                $errors[] = __('reports.errors.expression_invalid');
            }

            return;
        }

        if (isset($node['field'])) {
            $kind = $numeric((string) $node['field']);

            if ($kind === null) {
                $errors[] = __('reports.errors.expression_unknown_field', ['label' => (string) $node['field']]);
            } elseif ($kind === false) {
                $errors[] = __('reports.errors.expression_not_number', ['label' => $label((string) $node['field'])]);
            }

            return;
        }

        if (isset($node['neg']) && is_array($node['neg'])) {
            self::walk($node['neg'], $numeric, $label, $errors, $count);

            return;
        }

        if (isset($node['op']) && in_array($node['op'], ['+', '-', '*', '/'], true) && is_array($node['left'] ?? null) && is_array($node['right'] ?? null)) {
            self::walk($node['left'], $numeric, $label, $errors, $count);
            self::walk($node['right'], $numeric, $label, $errors, $count);

            return;
        }

        if (isset($node['call']) && is_array($node['args'] ?? null)) {
            $name = (string) $node['call'];
            [$min, $max] = FormulaAnalyzer::FUNCTIONS[$name] ?? [null, null];
            $args = array_values(array_filter($node['args'], 'is_array'));

            if ($min === null) {
                $errors[] = __('reports.errors.expression_function', ['function' => $name]);

                return;
            }

            if (count($args) < $min || ($max !== null && count($args) > $max)) {
                $errors[] = __('reports.errors.expression_arguments', ['function' => $name]);
            }

            if ($name === 'round' && isset($args[1]) && ! (isset($args[1]['num']) && ctype_digit((string) $args[1]['num']) && (int) $args[1]['num'] <= self::MAX_SCALE)) {
                $errors[] = __('reports.errors.expression_round', ['max' => self::MAX_SCALE]);
            }

            foreach ($args as $arg) {
                self::walk($arg, $numeric, $label, $errors, $count);
            }

            return;
        }

        $errors[] = __('reports.errors.expression_invalid');
    }

    /**
     * @param  list<string>  $args  SQL der Argumente
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function call(string $name, array $args, array $nodes): string
    {
        $all = implode(', ', $args);
        // Leer, sobald ein Argument leer ist (wie die berechneten Felder); LEAST/GREATEST behandeln NULL je Datenbank anders.
        $anyNull = implode(' OR ', array_map(fn (string $a): string => $a.' IS NULL', $args));

        return match ($name) {
            'round' => 'ROUND('.$args[0].', '.(isset($nodes[1]['num']) ? (int) $nodes[1]['num'] : 0).')',
            'abs' => 'ABS('.$args[0].')',
            'coalesce' => 'COALESCE('.$all.')',
            'min' => '(CASE WHEN '.$anyNull.' THEN NULL ELSE LEAST('.$all.') END)',
            'max' => '(CASE WHEN '.$anyNull.' THEN NULL ELSE GREATEST('.$all.') END)',
            default => throw new InvalidReportException([__('reports.errors.expression_function', ['function' => $name])]),
        };
    }

    private static function number(mixed $value): string
    {
        $value = (string) $value;

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidReportException([__('reports.errors.expression_invalid')]);
        }

        return $value;
    }

    private static function operator(string $op): string
    {
        return in_array($op, ['+', '-', '*'], true) ? $op : throw new InvalidReportException([__('reports.errors.expression_invalid')]);
    }

    private static function symbol(string $op): string
    {
        return ['*' => '×', '/' => '÷', '-' => '−', '+' => '+'][$op] ?? $op;
    }

    /** @param  array<string, mixed>  $child */
    private static function wrap(array $child, callable $label, string $parentOp, bool $right): string
    {
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $text = self::text($child, $label);

        if (! isset($child['op'])) {
            return $text;
        }

        $own = $precedence[$child['op']];
        $parent = $precedence[$parentOp];

        return $own < $parent || ($right && $own === $parent) ? '('.$text.')' : $text;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): string  $rename
     * @return array<string, mixed>
     */
    private static function rename(array $node, callable $rename): array
    {
        if (isset($node['path']) || isset($node['agg'])) {
            throw new InvalidReportException([__('reports.errors.expression_invalid')]);
        }

        // Nur die Struktur speichern (Positionen braucht nur die Fehlermeldung des Parsers).
        unset($node['pos']);

        if (isset($node['field'])) {
            $node['field'] = $rename((string) $node['field']);
        }

        foreach (['left', 'right', 'neg'] as $child) {
            if (isset($node[$child])) {
                $node[$child] = self::rename($node[$child], $rename);
            }
        }

        if (isset($node['args'])) {
            $node['args'] = array_map(fn (array $a): array => self::rename($a, $rename), $node['args']);
        }

        return $node;
    }
}
