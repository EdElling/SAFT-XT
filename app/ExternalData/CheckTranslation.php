<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\Rules\TableRule;

/**
 * In SAFT prüfbarer Teil einer CHECK-Regel: Werteliste, Min./Max. einer Spalte oder Vergleich zweier Spalten.
 */
final readonly class CheckTranslation
{
    /**
     * @param  list<string|int|float>|null  $values
     */
    private function __construct(
        public string $column,
        public ?array $values = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?TableRule $rule = null,
    ) {}

    /**
     * @param  list<string|int|float>  $values
     */
    public static function values(string $column, array $values): self
    {
        return new self($column, values: $values);
    }

    public static function range(string $column, int|float|null $min, int|float|null $max): self
    {
        return new self($column, min: $min, max: $max);
    }

    public static function rule(TableRule $rule): self
    {
        return new self((string) $rule->left->fieldName(), rule: $rule);
    }
}
