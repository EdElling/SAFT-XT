<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

/** Formelbaum zurück in Text, z. B. nach dem Umbenennen eines Felds. */
final class Printer
{
    private const array PRECEDENCE = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

    /**
     * @param  array<string, mixed>  $node
     */
    public static function print(array $node, int $parent = 0, bool $right = false): string
    {
        if (isset($node['op'])) {
            $own = self::PRECEDENCE[$node['op']];
            $text = self::print($node['left'], $own).' '.$node['op'].' '.self::print($node['right'], $own, true);

            // Klammern nur, wo sie nötig sind (rechts auch bei gleicher Stufe: a - (b - c)).
            return $own < $parent || ($right && $own === $parent) ? '('.$text.')' : $text;
        }

        return match (true) {
            isset($node['agg']) => $node['agg'].'('.self::aggregated($node).(($node['explicit'] ?? false) ? ', '.$node['via'] : '').')',
            isset($node['num']) => (string) $node['num'],
            isset($node['field']) => (string) $node['field'],
            isset($node['path']) => implode('.', $node['path']),
            isset($node['neg']) => '-'.self::print($node['neg'], 3),
            isset($node['call']) => $node['call'].'('.implode(', ', array_map(static fn (array $a): string => self::print($a), $node['args'])).')',
            default => '',
        };
    }

    /**
     * Was zusammengefasst wird: „tabelle.feld“, „tabelle“ (count) oder ein Ausdruck mit „tabelle.feld“.
     *
     * @param  array<string, mixed>  $node
     */
    private static function aggregated(array $node): string
    {
        $table = (string) $node['table'];

        if (isset($node['expr'])) {
            return self::print(self::map((array) $node['expr'], static fn (array $n): array => isset($n['field']) ? ['path' => [$table, $n['field']]] : $n));
        }

        return $table.(isset($node['field']) ? '.'.$node['field'] : '');
    }

    /**
     * Felder umbenennen: in Feldern desselben Datensatzes ($path = null) oder in Pfaden (Tabelle bzw. Feld).
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): array<string, mixed>  $rename
     * @return array<string, mixed>
     */
    public static function map(array $node, callable $rename): array
    {
        $node = $rename($node);

        foreach (['left', 'right', 'neg'] as $key) {
            if (isset($node[$key])) {
                $node[$key] = self::map($node[$key], $rename);
            }
        }

        if (isset($node['args'])) {
            $node['args'] = array_map(static fn (array $a): array => self::map($a, $rename), $node['args']);
        }

        return $node;
    }
}
