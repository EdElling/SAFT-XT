<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Printer;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\DynamicRecord;

/**
 * „Vergeben, wenn“ eines Nummernkreises (B-135, N3): eine Bedingung in der Formelsprache, z. B. status <> 'entwurf'.
 * Sie liest nur gespeicherte Felder desselben Datensatzes – keine berechneten Felder und keine Summen über
 * Kind-Datensätze, weil sie vor den Formeln ausgewertet wird (WritePhases). Dieselbe Prüfung gilt im Table-Designer,
 * in der App-Definition und für die KI (alle über den FieldDefinitionNormalizer).
 */
final class NumberCondition
{
    /** Option am Feld: Formeltext (wie eingegeben, normalisiert) und geprüfter Formelbaum. */
    public const string OPTION = 'assign_when';

    public const string AST = 'assign_when_ast';

    /**
     * Bedingung prüfen; leer = beim Anlegen vergeben (keine Optionen).
     *
     * @param  array<string, CustomField>  $fields  Felder der Tabelle nach Name
     * @return array<string, mixed>
     *
     * @throws FormulaException
     */
    public static function compile(string $formula, array $fields, string $self, ?string $table = null): array
    {
        $formula = trim($formula);

        if ($formula === '') {
            return [];
        }

        $compiled = app(FormulaAnalyzer::class)->compile($formula, $fields, $self, $table);

        if (FormulaAnalyzer::aggregates($compiled['ast']) !== []) {
            throw FormulaException::general(__('numbering.errors.condition_aggregate'));
        }

        foreach ($compiled['fields'] as $name) {
            if (($fields[$name] ?? null)?->type === FieldType::Computed) {
                throw FormulaException::general(__('numbering.errors.condition_computed', ['field' => $name]));
            }
        }

        return [self::OPTION => Printer::print($compiled['ast']), self::AST => $compiled['ast']];
    }

    /**
     * Hat das Feld eine Bedingung?
     *
     * @param  array<string, mixed>  $options
     */
    public static function has(array $options): bool
    {
        return is_array($options[self::AST] ?? null) && $options[self::AST] !== [];
    }

    /**
     * Ist die Bedingung für den Datensatz erfüllt (ohne Bedingung immer)?
     *
     * @param  array<string, mixed>  $options
     */
    public static function holds(DynamicRecord $record, array $options): bool
    {
        if (! self::has($options)) {
            return true;
        }

        $value = app(Evaluator::class)->evaluate((array) $options[self::AST], static fn (string $field): mixed => $record->getAttribute($field));

        return $value !== null && bccomp($value, '0', 12) !== 0;
    }

    /**
     * Felder, die die Bedingung liest (Umbenennen, Löschen, Deaktivieren).
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public static function fields(array $options): array
    {
        return self::has($options) ? FormulaAnalyzer::fields((array) $options[self::AST]) : [];
    }
}
