<?php

declare(strict_types=1);

namespace Saft\ChPruefungen;

use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\SaftPlugin;

/**
 * Beispiel-Plugin ohne eigene Seiten: Prüfregeln für Schweizer Nummern (im Table-Designer unter „Weitere Prüfungen“)
 * und die Formelfunktion rappen() für berechnete Felder.
 */
final class ChPruefungenPlugin extends SaftPlugin
{
    public const string ID = 'ch-pruefungen';

    private const string T = 'ch-pruefungen::pruefungen.';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __(self::T.'plugin');
    }

    public function description(): string
    {
        return __(self::T.'description');
    }

    public function fieldRules(): array
    {
        $rule = static fn (string $name, array $types, \Closure $valid): FieldRule => new FieldRule(
            name: $name,
            label: __(self::T.'rules.'.$name),
            types: $types,
            check: static fn (mixed $value): ?string => $valid((string) $value) ? null : __(self::T.'invalid.'.$name),
        );

        return [
            $rule('ahv', ['string'], SwissChecks::ahv(...)),
            $rule('iban', ['string'], SwissChecks::iban(...)),
            $rule('uid', ['string'], SwissChecks::uid(...)),
            $rule('plz', ['string', 'integer'], SwissChecks::postalCode(...)),
        ];
    }

    public function formulaFunctions(): array
    {
        return [
            new FormulaFunction(
                name: 'rappen',
                minArguments: 1,
                maxArguments: 1,
                evaluate: static fn (array $args): string => SwissChecks::roundToFiveRappen($args[0]),
                description: __(self::T.'functions.rappen'),
            ),
        ];
    }
}
