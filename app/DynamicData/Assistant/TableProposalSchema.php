<?php

declare(strict_types=1);

namespace App\DynamicData\Assistant;

use App\DynamicData\Files\FileKinds;

/**
 * JSON-Schemas der Antworten des Tabellen-Assistenten (neue Tabelle bzw. Änderungen an einer bestehenden) und die
 * Übersetzung eines vorgeschlagenen Felds in den Formularzustand des Table-Designers (FieldForm).
 */
final class TableProposalSchema
{
    /** Feldtypen, die der Assistent vorschlagen darf (keine berechneten Felder). */
    public const array TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'select', 'relation', 'file'];

    /** @return list<string> */
    public static function locales(): array
    {
        return array_keys((array) config('locales.available'));
    }

    /** @return array<string, mixed> neue Tabelle */
    public static function table(): array
    {
        return self::object([
            'kind' => ['type' => 'string', 'enum' => ['table', 'question']],
            'question' => self::nullable(['type' => 'string']),
            'name' => ['type' => 'string'],
            'label' => self::translations(),
            'description' => self::nullable(self::translations()),
            'menu_group' => self::nullable(['type' => 'string']),
            'fields' => ['type' => 'array', 'items' => self::field()],
        ]);
    }

    /** @return array<string, mixed> Änderungen an einer bestehenden Tabelle */
    public static function changes(): array
    {
        return self::object([
            'kind' => ['type' => 'string', 'enum' => ['changes', 'question']],
            'question' => self::nullable(['type' => 'string']),
            'add' => ['type' => 'array', 'items' => self::field()],
            'change' => ['type' => 'array', 'items' => self::object([
                'name' => ['type' => 'string'],
                'label' => self::nullable(self::translations()),
                'required' => self::nullable(['type' => 'boolean']),
                'unique' => self::nullable(['type' => 'boolean']),
                'searchable' => self::nullable(['type' => 'boolean']),
                'sortable' => self::nullable(['type' => 'boolean']),
                'add_choices' => ['type' => 'array', 'items' => self::choice()],
                'relation_display' => self::nullable(['type' => 'array', 'items' => ['type' => 'string']]),
            ])],
        ]);
    }

    /**
     * Vorgeschlagenes Feld → Formularzustand eines Felds (wie FieldForm).
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public static function toFormField(array $field): array
    {
        $type = in_array($field['type'] ?? null, self::TYPES, true) ? (string) $field['type'] : 'string';

        $options = match ($type) {
            'string' => ['length' => is_int($field['length'] ?? null) && $field['length'] > 0 ? $field['length'] : 255],
            'decimal' => ['precision' => is_int($field['precision'] ?? null) ? $field['precision'] : 12, 'scale' => is_int($field['scale'] ?? null) ? $field['scale'] : 2],
            'select' => ['options' => array_map(fn (array $c): array => ['value' => (string) ($c['value'] ?? ''), 'label' => self::cleanTranslations($c['label'] ?? null)], array_values(array_filter((array) ($field['choices'] ?? []), 'is_array')))],
            'relation' => ['table' => (string) ($field['relation_table'] ?? ''), 'display_fields' => array_values(array_map('strval', (array) ($field['relation_display'] ?? []))), 'separator' => ' '],
            // Grenzen prüft der FieldDefinitionNormalizer beim Anlegen.
            'file' => [
                'kinds' => FileKinds::normalize((array) ($field['file_kinds'] ?? [])),
                'max_mb' => is_int($field['max_mb'] ?? null) && $field['max_mb'] > 0 ? $field['max_mb'] : (int) config('dynamic_data.files.default_max_mb', 10),
                'max_files' => is_int($field['max_files'] ?? null) && $field['max_files'] > 0 ? $field['max_files'] : 1,
            ],
            default => [],
        };

        return [
            'label' => self::cleanTranslations($field['label'] ?? null),
            'name' => (string) ($field['name'] ?? ''),
            'type' => $type,
            'options' => $options,
            'validation_rules' => [],
            'is_required' => (bool) ($field['required'] ?? false),
            // Dateifelder sind weder eindeutig noch durchsuchbar noch sortierbar.
            'is_unique' => $type !== 'file' && (bool) ($field['unique'] ?? false),
            'is_searchable' => $type !== 'file' && (bool) ($field['searchable'] ?? false),
            'is_sortable' => $type !== 'file' && (bool) ($field['sortable'] ?? false),
            'show_in_table' => true,
            'show_in_form' => true,
        ];
    }

    /** @return array<string, string> */
    public static function cleanTranslations(mixed $value): array
    {
        return array_filter(array_map(fn (mixed $v): string => trim((string) $v), array_intersect_key((array) $value, array_flip(self::locales()))), static fn (string $v): bool => $v !== '');
    }

    /** @return array<string, mixed> */
    private static function field(): array
    {
        return self::object([
            'name' => ['type' => 'string'],
            'label' => self::translations(),
            'type' => ['type' => 'string', 'enum' => self::TYPES],
            'required' => ['type' => 'boolean'],
            'unique' => ['type' => 'boolean'],
            'searchable' => ['type' => 'boolean'],
            'sortable' => ['type' => 'boolean'],
            'length' => self::nullable(['type' => 'integer']),
            'precision' => self::nullable(['type' => 'integer']),
            'scale' => self::nullable(['type' => 'integer']),
            'choices' => ['type' => 'array', 'items' => self::choice()],
            'relation_table' => self::nullable(['type' => 'string']),
            'relation_display' => ['type' => 'array', 'items' => ['type' => 'string']],
            'file_kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [...array_keys(FileKinds::MIMES), FileKinds::ANY]]],
            'max_mb' => self::nullable(['type' => 'integer']),
            'max_files' => self::nullable(['type' => 'integer']),
        ]);
    }

    /** @return array<string, mixed> */
    private static function choice(): array
    {
        return self::object(['value' => ['type' => 'string'], 'label' => self::translations()]);
    }

    /** @return array<string, mixed> Bezeichnung in allen Sprachen */
    private static function translations(): array
    {
        return self::object(array_fill_keys(self::locales(), ['type' => 'string']));
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private static function nullable(array $type): array
    {
        return ['anyOf' => [$type, ['type' => 'null']]];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
