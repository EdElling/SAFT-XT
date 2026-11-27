<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Recalculator;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Art „Formel“ einer Sammeländerung (BULK_EDIT.md §12 Fragen 5–8): eine Formel der Formelsprache je Datensatz. Das
 * eigene Feld ist erlaubt und meint den bisherigen Wert; berechnete Felder und Summen über Kind-Datensätze lesen den
 * gespeicherten Stand. Ausgewertet wird streng (Kontext „Sammeländerung“, FORMULA_SPEC.md Abschnitt 1): eine Division
 * durch 0 ist ein Fehler, ein leeres Ergebnis lässt den Datensatz unverändert.
 */
final class BulkFormula
{
    /**
     * Formel prüfen und in einen Baum übersetzen.
     *
     * @return array<string, mixed>
     *
     * @throws FormulaException
     */
    public static function compile(CustomTable $table, string $formula): array
    {
        if (trim($formula) === '') {
            throw FormulaException::general(__('data.bulk.errors.formula_empty'));
        }

        // Kein „eigenes Feld“: In einer Sammeländerung ist es der bisherige Wert, kein Kreis.
        return app(FormulaAnalyzer::class)->compile($formula, $table->fields->keyBy('name')->all(), '', $table->name)['ast'];
    }

    /**
     * Ergebnis für den Datensatz, ungerundet; null = leer.
     *
     * @param  array<string, mixed>  $ast
     *
     * @throws FormulaException bei Division durch 0
     */
    public static function evaluate(array $ast, DynamicRecord $record): ?string
    {
        return app(Evaluator::class)->evaluateStrict(
            $ast,
            static fn (string $field): mixed => $record->getAttribute($field),
            static fn (array $node): mixed => Recalculator::aggregate($node, $record->getKey()),
        );
    }
}
