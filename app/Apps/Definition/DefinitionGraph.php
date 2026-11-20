<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\DynamicData\Diagram\DataModelGraph;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;

/**
 * Datenmodell aus einer App-Definition (Vorschau beim Installieren, B-107 Etappe 4b): Tabellen und Verknüpfungen als
 * nicht gespeicherte Modelle für den MermaidErRenderer – ohne Zugriff auf die Datenbank.
 */
final class DefinitionGraph
{
    /** @param  array<string, mixed>  $definition */
    public static function build(array $definition): DataModelGraph
    {
        $tables = [];

        foreach ((array) ($definition['tables'] ?? []) as $data) {
            $tables[$data['name']] = self::table($data, external: false);
        }

        foreach ((array) ($definition['external_tables'] ?? []) as $data) {
            $tables[$data['name']] = self::table($data, external: true);
        }

        $details = [];

        foreach ((array) ($definition['tables'] ?? []) as $data) {
            foreach ((array) ($data['details'] ?? []) as $detail) {
                $details[$data['name']][] = (string) $detail;
            }
        }

        $edges = [];

        foreach ($tables as $name => $table) {
            foreach ($table->fields as $field) {
                $target = (string) ($field->optionsArray()['table'] ?? '');

                if ($field->type === FieldType::Relation && isset($tables[$target])) {
                    $edges[] = [
                        'from' => $name,
                        'to' => $target,
                        'field' => $field,
                        'required' => (bool) $field->is_required,
                        'detail' => in_array($name.'.'.$field->name, $details[$target] ?? [], true),
                        'link' => null,
                    ];
                }
            }
        }

        return new DataModelGraph($tables, [], $edges);
    }

    /** @param  array<string, mixed>  $data */
    private static function table(array $data, bool $external): CustomTable
    {
        $table = new CustomTable([
            'name' => $data['name'],
            'label' => $data['label'] ?? $data['name'],
            'source' => $external ? CustomTable::SOURCE_EXTERNAL : CustomTable::SOURCE_DESIGNER,
            'key_columns' => (array) ($data['key_columns'] ?? []),
            'is_active' => true,
        ]);

        $fields = collect((array) ($data['fields'] ?? []))
            ->filter(static fn (mixed $f): bool => is_array($f) && FieldType::tryFrom((string) ($f['type'] ?? '')) !== null && ($f['is_active'] ?? true))
            ->map(static fn (array $f): CustomField => new CustomField([
                'name' => $f['name'],
                'label' => $f['label'] ?? $f['name'],
                'type' => $f['type'],
                'options' => $f['options'] ?? [],
                'is_required' => (bool) ($f['is_required'] ?? false),
                'is_unique' => (bool) ($f['is_unique'] ?? false),
            ]))
            ->values();

        $table->setRelation('fields', $fields);
        $table->setRelation('databaseConnection', $external && filled($data['connection'] ?? null) ? new DatabaseConnection(['name' => $data['connection']]) : null);

        return $table;
    }
}
