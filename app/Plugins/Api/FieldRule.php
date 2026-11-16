<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use Closure;

/**
 * Zusätzliche Prüfregel für Felder (SaftPlugin::fieldRules()), im Table-Designer unter „Weitere Prüfungen“ wählbar.
 * Sie wirkt bei der Erfassung, beim Import und über Saft::table(); leere Werte prüft sie nicht (dafür gibt es
 * „Pflichtfeld“). Gespeichert wird sie im Feld als „<plugin-id>.<name>“. Fehlt das Plugin oder ruht es, wird die
 * Regel übersprungen.
 *
 * @api
 */
final readonly class FieldRule
{
    /** Erlaubte Namen: Kleinbuchstaben, Ziffern und Bindestriche. */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** Feldtypen, für die Plugins Regeln anbieten können. */
    public const array TYPES = ['string', 'text', 'integer', 'decimal'];

    /**
     * @param  string  $name  Name innerhalb des Plugins, z. B. „iban“
     * @param  string  $label  Bezeichnung in der aktuellen Sprache
     * @param  list<string>  $types  Feldtypen, für die sie wählbar ist: string, text, integer, decimal
     * @param  Closure(mixed): ?string  $check  Fehlermeldung oder null, wenn der Wert passt
     * @param  string|null  $description  Was die Regel verlangt, in der aktuellen Sprache, z. B. „Ein Buchstabe, Bindestrich,
     *                                    vier Ziffern (A-1234)“ – erscheint im Table-Designer, im Datenmodell für den
     *                                    Hilfe-Assistenten und im Entwickler-Kontext (seit API 1.6)
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $types,
        public Closure $check,
        public ?string $description = null,
    ) {}
}
