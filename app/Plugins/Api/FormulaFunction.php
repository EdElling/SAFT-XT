<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use Closure;

/**
 * Zusätzliche Funktion für berechnete Felder (SaftPlugin::formulaFunctions()), z. B. rappen(betrag). Formeln rechnen
 * mit Dezimalzahlen: Argumente und Ergebnis sind Zahlen als Text („12.35“, „-3“). Ist ein Argument leer, ist das
 * Ergebnis leer, ohne dass die Funktion aufgerufen wird. In Ausdrücken von Auswertungen (SQL) gibt es sie nicht.
 *
 * @api
 */
final readonly class FormulaFunction
{
    /** Erlaubte Namen: Kleinbuchstaben, Ziffern und _, beginnend mit einem Buchstaben. */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  string  $name  Name in der Formel (klein geschrieben, darf keine eingebaute Funktion sein)
     * @param  Closure(list<string>): (string|int|float|null)  $evaluate  Ergebnis; null = leer
     * @param  string  $description  kurze Erklärung mit Beispiel für die Formelhilfe im Table-Designer
     */
    public function __construct(
        public string $name,
        public int $minArguments,
        public ?int $maxArguments,
        public Closure $evaluate,
        public string $description = '',
    ) {}
}
