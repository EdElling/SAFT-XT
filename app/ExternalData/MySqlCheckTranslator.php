<?php

declare(strict_types=1);

namespace App\ExternalData;

/**
 * CHECK-Regeln von MySQL (information_schema.check_constraints), z. B.
 *   (`typ` in (_utf8mb4\'hochschule\',_utf8mb4\'behoerde\'))  → Werteliste
 *   (`jahr` between 1900 and 2100)                           → Min./Max.
 *   ((`ende` is null) or (`ende` >= `beginn`))               → Vergleich zweier Spalten
 *
 * Die Regel wird in die Schreibweise gebracht, die PostgresCheckTranslator erkennt; die
 * Entscheidung, was übersetzbar ist, bleibt an einer Stelle (konservativ).
 */
final class MySqlCheckTranslator implements CheckTranslator
{
    private const string IDENT = '[A-Za-z_][A-Za-z0-9_]*';

    private const string NUMBER = '-?\d+(?:\.\d+)?';

    public function __construct(private readonly PostgresCheckTranslator $rules = new PostgresCheckTranslator) {}

    public function translate(CheckConstraintInfo $check, array $columnTypes): ?CheckTranslation
    {
        return $this->rules->translate(new CheckConstraintInfo($check->name, self::normalize($check->clause), $check->columns), $columnTypes);
    }

    public static function normalize(string $clause): string
    {
        // Maskierte Hochkommas der Ausgabe von information_schema.
        $clause = str_replace("\\'", "'", $clause);
        // Zeichensatz-Präfix vor Zeichenketten: _utf8mb4'wert' → 'wert'.
        $clause = (string) preg_replace("/_[a-z0-9]+'/i", "'", $clause);
        $clause = (string) preg_replace('/`('.self::IDENT.')`/', '$1', $clause);
        // x between a and b → x >= a and x <= b
        $clause = (string) preg_replace(
            '/\b('.self::IDENT.') between ('.self::NUMBER.') and ('.self::NUMBER.')/i',
            '$1 >= $2 AND $1 <= $3',
            $clause,
        );

        return trim($clause);
    }
}
