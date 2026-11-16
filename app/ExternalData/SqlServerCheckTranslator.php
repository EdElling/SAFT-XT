<?php

declare(strict_types=1);

namespace App\ExternalData;

/**
 * CHECK-Regeln von SQL Server (information_schema.check_constraints). SQL Server speichert sie
 * umgeschrieben, z. B.
 *   ([typ]='behoerde' OR [typ]='unternehmen' OR [typ]='hochschule')  → Werteliste
 *   ([jahr]>=(1900) AND [jahr]<=(2100))                               → Min./Max.
 *   ([ende] IS NULL OR [ende]>=[beginn])                              → Vergleich zweier Spalten
 *
 * Die Regel wird in die Schreibweise gebracht, die PostgresCheckTranslator erkennt; die
 * Entscheidung, was übersetzbar ist, bleibt an einer Stelle (konservativ).
 */
final class SqlServerCheckTranslator implements CheckTranslator
{
    private const string IDENT = '[A-Za-z_][A-Za-z0-9_]*';

    private const string STRING = "'(?:[^']|'')*'";

    public function __construct(private readonly PostgresCheckTranslator $rules = new PostgresCheckTranslator) {}

    public function translate(CheckConstraintInfo $check, array $columnTypes): ?CheckTranslation
    {
        return $this->rules->translate(new CheckConstraintInfo($check->name, self::normalize($check->clause), $check->columns), $columnTypes);
    }

    public static function normalize(string $clause): string
    {
        // Außerhalb von Zeichenketten: N'…' → '…', [name] → name, Leerzeichen um Vergleiche.
        $clause = (string) preg_replace_callback(
            '/N?('.self::STRING.')|\[('.self::IDENT.')\]|(>=|<=|<>|!=|=|<|>)/',
            static fn (array $m): string => match (true) {
                ($m[1] ?? '') !== '' => $m[1],
                ($m[2] ?? '') !== '' => $m[2],
                default => ' '.$m[3].' ',
            },
            $clause,
        );
        $clause = trim((string) preg_replace('/\s+/', ' ', $clause));

        return self::orChainToIn($clause);
    }

    /** (x = 'c' OR x = 'b' OR x = 'a') → x IN ('a', 'b', 'c'): so speichert SQL Server eine Werteliste. */
    private static function orChainToIn(string $clause): string
    {
        $inner = $clause;

        while (str_starts_with($inner, '(') && str_ends_with($inner, ')') && self::balanced(substr($inner, 1, -1))) {
            $inner = substr($inner, 1, -1);
        }

        $item = '\(?\s*('.self::IDENT.') = ('.self::STRING.')\s*\)?';

        if (preg_match('/^'.$item.'(?: OR '.$item.')+$/i', $inner) !== 1) {
            return $clause;
        }

        preg_match_all('/'.$item.'/i', $inner, $parts, PREG_SET_ORDER);
        $columns = array_unique(array_map(static fn (array $p): string => strtolower($p[1]), $parts));

        if (count($columns) !== 1) {
            return $clause;
        }

        // SQL Server speichert IN (a, b, c) als c OR b OR a: zurück in die ursprüngliche Reihenfolge.
        return $parts[0][1].' IN ('.implode(', ', array_reverse(array_column($parts, 2))).')';
    }

    /** Klammern außerhalb von Zeichenketten ausgeglichen (äußeres Paar darf entfernt werden). */
    private static function balanced(string $text): bool
    {
        $depth = 0;
        $text = (string) preg_replace('/'.self::STRING.'/', "''", $text);

        foreach (str_split($text) as $char) {
            $depth += match ($char) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }
}
