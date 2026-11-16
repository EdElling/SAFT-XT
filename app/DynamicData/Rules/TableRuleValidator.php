<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Support\AppLocale;
use App\Support\Translations;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hängt die Regeln einer Tabelle (z. B. „Ende ≥ Beginn“) als Prüfung an das betroffene Formularfeld:
 * an das linke Feld, sonst – wenn es nicht im Formular ist – an das rechte. Werte von Feldern, die
 * nicht im Formular stehen (gesperrt, nur lesbar), kommen aus dem gespeicherten Datensatz.
 */
final class TableRuleValidator
{
    public function __construct(private readonly RuleEvaluator $evaluator) {}

    /**
     * @param  list<Field>  $components
     */
    public function apply(CustomTable $table, array $components): void
    {
        $byName = [];

        foreach ($components as $component) {
            $byName[$component->getName()] = $component;
        }

        $fields = $table->fields->keyBy('name');

        foreach ($table->tableRules() as $rule) {
            $left = $rule->left->fieldName();
            $right = $rule->right->fieldName();

            if ($left !== null && isset($byName[$left])) {
                $byName[$left]->rule($this->closure($rule, $fields->all(), $rule, array_keys($byName)));
            } elseif ($right !== null && isset($byName[$right])) {
                // Gleiche Regel, aus Sicht des rechten Felds formuliert.
                $mirrored = new TableRule($rule->right, TableRule::mirror($rule->operator), $rule->left, $rule->whenFilled, $rule->source, $rule->constraint);
                $byName[$right]->rule($this->closure($rule, $fields->all(), $mirrored, array_keys($byName)));
            }
        }
    }

    /**
     * @param  array<string, CustomField>  $fields
     * @param  list<string>  $inForm
     */
    private function closure(TableRule $rule, array $fields, TableRule $shown, array $inForm): Closure
    {
        return fn (Get $get, ?Model $record): TableRuleCheck => new TableRuleCheck(
            $this->evaluator,
            $rule,
            static fn (string $name): mixed => in_array($name, $inForm, true) ? $get($name) : $record?->getAttribute($name),
            self::message($shown, $fields),
        );
    }

    /**
     * @param  array<string, CustomField>  $fields
     */
    public static function message(TableRule $rule, array $fields): string
    {
        // Eigene Meldung aus dem Table-Designer.
        if ($rule->message !== null && ($own = Translations::pick($rule->message)) !== '') {
            return $own;
        }

        $left = $fields[(string) $rule->left->fieldName()] ?? null;
        $other = match (true) {
            $rule->right->isField() => '„'.((string) ($fields[(string) $rule->right->fieldName()]->label ?? $rule->right->fieldName())).'“',
            $left?->type === FieldType::Boolean => __((bool) $rule->right->value ? 'common.yes' : 'common.no'),
            $left?->type === FieldType::Date => (string) AppLocale::date(Carbon::parse((string) $rule->right->value)),
            $left?->type === FieldType::DateTime => (string) AppLocale::dateTime(Carbon::parse((string) $rule->right->value)),
            default => (string) $rule->right->value,
        };
        $kind = in_array($left?->type, [FieldType::Date, FieldType::DateTime], true) ? 'date' : 'compare';

        return __("data.rules.{$kind}.{$rule->operator}", ['other' => $other]);
    }
}
