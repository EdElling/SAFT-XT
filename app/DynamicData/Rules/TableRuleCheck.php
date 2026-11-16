<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Prüfung einer Regel beim Formularfeld. Implizit, damit Laravel sie auch bei leerem Feld ausführt
 * (sonst griffe „nur wenn gefüllt: aus“ nie).
 */
final class TableRuleCheck implements ValidationRule
{
    public bool $implicit = true;

    /**
     * @param  Closure(string): mixed  $values  Werte der Felder desselben Datensatzes
     */
    public function __construct(
        private readonly RuleEvaluator $evaluator,
        private readonly TableRule $rule,
        private readonly Closure $values,
        private readonly string $message,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->evaluator->passes($this->rule, $this->values) === false) {
            $fail($this->message);
        }
    }
}
