<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Fortlaufender Vorschlag für Ganzzahl-Felder: höchster Wert + 1, optional je Wert einer Verknüpfung
 * (z. B. Position je Bestellung). Zählt nur nicht gelöschte Datensätze; über den Query Builder, portabel.
 */
final class SequenceSuggester
{
    /** Optionen am Feld. */
    public const string OPTION = 'suggest_next';

    public const string SCOPE = 'suggest_scope';

    public static function applies(CustomField $field): bool
    {
        return $field->type === FieldType::Integer && (bool) ($field->optionsArray()[self::OPTION] ?? false);
    }

    public static function scope(CustomField $field): ?string
    {
        $scope = $field->optionsArray()[self::SCOPE] ?? null;

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    /**
     * @return int|null null, wenn der Bereich nötig, aber (noch) nicht gewählt ist
     */
    public function next(CustomTable $table, CustomField $field, mixed $scopeValue = null): ?int
    {
        return self::nextIn(DynamicRecord::forTable($table), $field->name, self::scope($field), $scopeValue);
    }

    public static function nextIn(DynamicRecord $model, string $field, ?string $scope, mixed $scopeValue): ?int
    {
        if ($scope !== null && ($scopeValue === null || $scopeValue === '')) {
            return null;
        }

        $max = $model->newQuery()
            ->when($scope !== null, fn ($q) => $q->where($scope, $scopeValue))
            ->max($field);

        return (int) $max + 1;
    }
}
