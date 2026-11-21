<?php

declare(strict_types=1);

namespace App\DynamicData\Formula;

use App\Apps\AppInterfaces;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Formeln vorgeschlagener Felder prüfen, bevor es sie gibt – für den Tabellen-Assistenten (B-122) und den
 * KI-App-Designer (B-107, Etappe 7a). Die Prüfung beim Anlegen (FieldDefinitionNormalizer) kennt nur gespeicherte Felder.
 */
final class FormulaCheck
{
    /**
     * Formeln berechneter Felder (B-122) gegen die Tabelle samt den vorgeschlagenen Feldern prüfen – in
     * Vorschlagsreihenfolge, mit Kreisbezug und ohne Summen über Kind-Tabellen einer anderen App. Die Prüfung beim
     * Anlegen (FieldDefinitionNormalizer) kennt nur gespeicherte Felder und sähe neue Felder nicht.
     *
     * @param  list<array<string, mixed>>  $forms  vorgeschlagene Felder (Formularzustand)
     * @param  iterable<CustomField>  $existing
     * @return list<string>
     */
    public function problems(array $forms, iterable $existing, ?string $table, ?int $appId): array
    {
        $fields = [];
        $errors = [];

        foreach ($existing as $field) {
            $fields[$field->name] = $field;
        }

        foreach ($forms as $form) {
            $name = (string) ($form['name'] ?? '');
            $type = FieldType::tryFrom((string) ($form['type'] ?? ''));

            if ($name === '' || $type === null) {
                continue;
            }

            $options = (array) ($form['options'] ?? []);

            if ($type === FieldType::Computed) {
                try {
                    $compiled = app(FormulaAnalyzer::class)->compile((string) ($options['formula'] ?? ''), $fields, $name, $table);
                    FormulaAnalyzer::assertNoCycle($fields, $name, $compiled['ast']);

                    foreach (FormulaAnalyzer::aggregates($compiled['ast']) as $aggregate) {
                        $child = CustomTable::query()->where('name', $aggregate['table'])->first();

                        if ($child !== null && AppInterfaces::foreign($child, $appId)) {
                            throw new BusinessRuleException(__('apps.errors.cross_app_aggregate', ['table' => (string) $child->label]));
                        }
                    }

                    $options['ast'] = $compiled['ast'];
                } catch (BusinessRuleException $e) {
                    $errors[] = __('tables_assistant.errors.field', ['name' => $name, 'error' => $e->getMessage()]);
                }
            }

            $fields[$name] = new CustomField(['name' => $name, 'type' => $type, 'label' => $form['label'] ?? [], 'options' => $options, 'is_active' => true]);
        }

        return $errors;
    }
}
