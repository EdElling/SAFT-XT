<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\Rules\Operand;
use App\DynamicData\Rules\TableRule;

/**
 * Erkennt die Formen, in denen PostgreSQL einfache CHECK-Regeln ausgibt (pg_get_constraintdef bzw.
 * information_schema.check_constraints), z. B.
 *   ((status)::text = ANY ((ARRAY['geplant'::character varying, …])::text[]))  → Werteliste
 *   ((jahr >= 1900) AND (jahr <= 2100))                                       → Min./Max.
 *   ((ende IS NULL) OR (ende >= beginn))                                      → Vergleich zweier Spalten
 *
 * Bewusst konservativ: Alles andere bleibt unübersetzt (lieber keine als eine falsche Prüfung).
 * CHECK gilt in SQL als erfüllt, wenn ein Wert NULL ist – deshalb prüfen alle Übersetzungen nur
 * gefüllte Werte.
 */
final class PostgresCheckTranslator implements CheckTranslator
{
    private const string IDENT = '[a-z_][a-z0-9_]*';

    private const string NUMBER = '-?\d+(?:\.\d+)?';

    private const string STRING = "'(?:[^']|'')*'";

    public function translate(CheckConstraintInfo $check, array $columnTypes): ?CheckTranslation
    {
        $expression = self::normalize($check->clause);

        return $this->values($expression, $columnTypes)
            ?? $this->range($expression, $columnTypes)
            ?? $this->comparison($expression, $columnTypes, $check->name);
    }

    /** Typumwandlungen, Anführungszeichen um Namen und äußere Klammern entfernen. */
    public static function normalize(string $clause): string
    {
        $clause = trim($clause);
        $clause = (string) preg_replace('/^CHECK\s*/i', '', $clause);
        // NOT VALID: bestehende Zeilen wurden nicht geprüft, neue und geänderte schon.
        $clause = (string) preg_replace('/\s+NOT VALID$/i', '', $clause);
        // ::text, ::character varying, ::numeric(10,2), ::text[] – nicht innerhalb von Zeichenketten.
        $clause = (string) preg_replace_callback(
            "/('(?:[^']|'')*')|::\"?(?:character varying|double precision|time(?:stamp)? with(?:out)? time zone|[a-z_][a-z0-9_]*)\"?(?:\(\d+(?:,\s*\d+)?\))?(?:\[\])?/i",
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : '',
            $clause,
        );
        $clause = (string) preg_replace('/"('.self::IDENT.')"/', '$1', $clause);
        $clause = (string) preg_replace('/\s+/', ' ', $clause);
        // Klammern um einzelne Namen oder Zahlen: (status), (0) – nicht innerhalb von Zeichenketten.
        do {
            $before = $clause;
            $clause = (string) preg_replace_callback(
                "/('(?:[^']|'')*')|\\((".self::IDENT.'|'.self::NUMBER.')\\)/i',
                static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : $m[2],
                $clause,
            );
        } while ($clause !== $before);

        return self::unwrap(trim($clause));
    }

    /**
     * @param  array<string, string>  $types
     */
    private function values(string $expression, array $types): ?CheckTranslation
    {
        if (preg_match('/^\(?('.self::IDENT.')\)? = ANY \(+ARRAY\[(.*)\]\)+$/i', $expression, $m) !== 1
            && preg_match('/^\(?('.self::IDENT.')\)? IN \((.*)\)$/i', $expression, $m) !== 1) {
            return null;
        }

        $column = strtolower($m[1]);

        if (! isset($types[$column]) || preg_match_all('/\s*('.self::STRING.'|'.self::NUMBER.')\s*(?:,|$)/', $m[2], $items) === 0) {
            return null;
        }

        // Jeder Teil der Liste muss erkannt sein.
        if (implode(',', array_map('trim', $items[1])) !== (string) preg_replace('/\s*,\s*/', ',', trim($m[2]))) {
            return null;
        }

        return CheckTranslation::values($column, array_map(self::literal(...), $items[1]));
    }

    /**
     * @param  array<string, string>  $types
     */
    private function range(string $expression, array $types): ?CheckTranslation
    {
        $column = null;
        $min = null;
        $max = null;

        foreach (self::split($expression, 'AND') as $part) {
            $flipped = [];

            if (preg_match('/^('.self::IDENT.') (>=|<=|>|<) ('.self::NUMBER.')$/i', $part, $m) !== 1
                && preg_match('/^('.self::NUMBER.') (>=|<=|>|<) ('.self::IDENT.')$/i', $part, $flipped) !== 1) {
                return null;
            }

            if ($flipped !== []) {
                $m = [$part, $flipped[3], TableRule::mirror($flipped[2]), $flipped[1]];
            }

            $name = strtolower($m[1]);
            $type = $types[$name] ?? null;

            if (! in_array($type, ['integer', 'decimal'], true) || ($column !== null && $column !== $name)) {
                return null;
            }

            $column = $name;
            $number = str_contains($m[3], '.') ? (float) $m[3] : (int) $m[3];

            // Echte Ungleichungen nur bei ganzen Zahlen exakt übersetzbar.
            if (in_array($m[2], ['>', '<'], true)) {
                if ($type !== 'integer' || ! is_int($number)) {
                    return null;
                }

                $number += $m[2] === '>' ? 1 : -1;
            }

            if (in_array($m[2], ['>=', '>'], true)) {
                $min = $min === null ? $number : max($min, $number);
            } else {
                $max = $max === null ? $number : min($max, $number);
            }
        }

        return $column === null ? null : CheckTranslation::range($column, $min, $max);
    }

    /**
     * Vergleich zweier Spalten, optional mit vorangestellten „spalte IS NULL OR“.
     *
     * @param  array<string, string>  $types
     */
    private function comparison(string $expression, array $types, string $name): ?CheckTranslation
    {
        $parts = self::split($expression, 'OR');
        $comparison = array_pop($parts);

        if (preg_match('/^('.self::IDENT.') (=|<>|!=|>=|<=|>|<) ('.self::IDENT.')$/i', (string) $comparison, $m) !== 1) {
            return null;
        }

        [$left, $right] = [strtolower($m[1]), strtolower($m[3])];

        if (! isset($types[$left], $types[$right]) || $left === $right) {
            return null;
        }

        foreach ($parts as $part) {
            if (preg_match('/^('.self::IDENT.') IS NULL$/i', $part, $null) !== 1 || ! in_array(strtolower($null[1]), [$left, $right], true)) {
                return null;
            }
        }

        return CheckTranslation::rule(new TableRule(
            Operand::field($left),
            $m[2] === '!=' ? '<>' : $m[2],
            Operand::field($right),
            whenFilled: true,
            source: TableRule::SOURCE_DATABASE,
            constraint: $name,
        ));
    }

    /**
     * Teilt an AND bzw. OR auf oberster Klammerebene; Teile ohne äußere Klammern.
     *
     * @return list<string>
     */
    private static function split(string $expression, string $operator): array
    {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $current = '';
        $token = ' '.$operator.' ';
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                $depth--;
            } elseif (! $quoted && $depth === 0 && strcasecmp(substr($expression, $i, strlen($token)), $token) === 0) {
                $parts[] = self::unwrap(trim($current));
                $current = '';
                $i += strlen($token) - 1;

                continue;
            }

            $current .= $char;
        }

        $parts[] = self::unwrap(trim($current));

        return $parts;
    }

    /** Entfernt Klammern, die den ganzen Ausdruck umschließen. */
    private static function unwrap(string $expression): string
    {
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')') && self::closes($expression)) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /** Schließt die erste Klammer erst am Ende? */
    private static function closes(string $expression): bool
    {
        $depth = 0;
        $quoted = false;
        $last = strlen($expression) - 1;

        for ($i = 0; $i <= $last; $i++) {
            $char = $expression[$i];

            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                $depth--;

                if ($depth === 0 && $i < $last) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    private static function literal(string $item): string|int|float
    {
        $item = trim($item);

        if (str_starts_with($item, "'")) {
            return str_replace("''", "'", substr($item, 1, -1));
        }

        return str_contains($item, '.') ? (float) $item : (int) $item;
    }
}
