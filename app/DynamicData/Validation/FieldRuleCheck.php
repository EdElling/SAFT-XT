<?php

declare(strict_types=1);

namespace App\DynamicData\Validation;

use App\Models\CustomField;
use App\Plugins\PluginRegistry;
use Throwable;

/**
 * Prüfregeln aus Plugins („Weitere Prüfungen“ eines Felds, validation_rules.checks). Regeln, deren Plugin fehlt oder
 * ruht oder die nicht zum Feldtyp passen, werden übersprungen; der Table-Designer weist darauf hin.
 */
final readonly class FieldRuleCheck
{
    public const string OPTION = 'checks';

    public function __construct(private PluginRegistry $plugins) {}

    /** @return list<string> gewählte Regeln des Felds („<plugin-id>.<name>“) */
    public static function selected(CustomField $field): array
    {
        return array_values(array_map('strval', (array) (((array) ($field->validation_rules ?? []))[self::OPTION] ?? [])));
    }

    /** @return list<string> Fehlermeldungen für einen nicht leeren Wert */
    public function messages(CustomField $field, mixed $value): array
    {
        $selected = self::selected($field);

        if ($selected === [] || $value === null || $value === '') {
            return [];
        }

        $rules = $this->plugins->fieldRules($field->type->value);
        $messages = [];

        foreach ($selected as $key) {
            if (! isset($rules[$key])) {
                continue;
            }

            try {
                $message = ($rules[$key]->check)($value);
            } catch (Throwable $e) {
                // Ein Fehler im Plugin soll die Erfassung nicht blockieren.
                report($e);
                $message = null;
            }

            if ($message !== null && $message !== '') {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
