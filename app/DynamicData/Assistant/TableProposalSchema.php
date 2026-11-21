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
    /** Feldtypen, die der Assistent vorschlagen darf; berechnete Felder mit Formel (B-122). */
    public const array TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'select', 'relation', 'file', 'computed'];

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
            'app' => self::nullable(['type' => 'integer']),
            'name' => ['type' => 'string'],
            'label' => self::translations(),
            'description' => self::nullable(self::translations()),
            'icon' => self::nullable(['type' => 'string']),
            'menu_group' => self::nullable(['type' => 'string']),
            'fields' => ['type' => 'array', 'items' => self::field()],
        ]);
    }

    /** @return array<string, mixed> Änderungen an einer bestehenden Tabelle */
    public static function changes(): array
    {
        return self::object([
            'kind' => ['type' => 'string', 'enum' => ['changes', 'question']],
            // Einfache Texte statt „Text oder null“: Das Schema der Änderungen liegt an der Grenze der Anthropic-
            // Grammatik („compiled grammar is too large“). Leer = keine Rückfrage bzw. Symbol unverändert.
            'question' => ['type' => 'string'],
            'icon' => ['type' => 'string'],
            'add' => ['type' => 'array', 'items' => self::field()],
            'change' => ['type' => 'array', 'items' => self::object([
                'name' => ['type' => 'string'],
                // Leere Bezeichnungen bzw. Anzeigefelder = unverändert (spart „oder null“ für die Grammatik-Grenze).
                'label' => self::translations(),
                'required' => self::nullable(['type' => 'boolean']),
                'unique' => self::nullable(['type' => 'boolean']),
                'searchable' => self::nullable(['type' => 'boolean']),
                'sortable' => self::nullable(['type' => 'boolean']),
                'add_choices' => ['type' => 'array', 'items' => self::choice()],
                'relation_display' => ['type' => 'array', 'items' => ['type' => 'string']],
                // Geänderte Feldregeln als Text (RuleText), „max=“ entfernt; leer = unverändert.
                'rules' => ['type' => 'string'],
            ])],
            // Neue Tabellenregeln als Text (RuleText), z. B. „ende >= beginn“.
            'add_rules' => ['type' => 'array', 'items' => ['type' => 'string']],
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
            // Genauigkeit 0 = Vorgabe 12,2; mit Genauigkeit gilt die angegebene Zahl der Nachkommastellen (auch 0).
            'decimal' => is_int($field['precision'] ?? null) && $field['precision'] > 0
                ? ['precision' => $field['precision'], 'scale' => is_int($field['scale'] ?? null) ? $field['scale'] : 2]
                : ['precision' => 12, 'scale' => 2],
            'select' => ['options' => array_map(fn (array $c): array => ['value' => (string) ($c['value'] ?? ''), 'label' => self::cleanTranslations($c['label'] ?? null)], array_values(array_filter((array) ($field['choices'] ?? []), 'is_array')))],
            'relation' => ['table' => (string) ($field['relation_table'] ?? ''), 'display_fields' => array_values(array_map('strval', (array) ($field['relation_display'] ?? []))), 'separator' => ' '],
            // Grenzen prüft der FieldDefinitionNormalizer beim Anlegen.
            'file' => [
                'kinds' => FileKinds::normalize((array) ($field['file_kinds'] ?? [])),
                'max_mb' => is_int($field['max_mb'] ?? null) && $field['max_mb'] > 0 ? $field['max_mb'] : (int) config('dynamic_data.files.default_max_mb', 10),
                'max_files' => is_int($field['max_files'] ?? null) && $field['max_files'] > 0 ? $field['max_files'] : 1,
            ],
            // Berechnet (B-122): Genauigkeit mit 0 Nachkommastellen = Ganzzahl, sonst Dezimalzahl (Vorgabe 12,2).
            'computed' => ['formula' => trim((string) ($field['formula'] ?? '')), ...(is_int($field['precision'] ?? null) && $field['precision'] > 0 && ($field['scale'] ?? null) === 0
                ? ['result' => 'integer']
                : ['result' => 'decimal', ...(is_int($field['precision'] ?? null) && $field['precision'] > 0
                    ? ['precision' => $field['precision'], 'scale' => is_int($field['scale'] ?? null) ? $field['scale'] : 2]
                    : ['precision' => 12, 'scale' => 2])])],
            default => [],
        };

        return [
            'label' => self::cleanTranslations($field['label'] ?? null),
            'name' => (string) ($field['name'] ?? ''),
            'type' => $type,
            'options' => $options,
            'validation_rules' => self::fieldRules($field['rules'] ?? null),
            'is_required' => (bool) ($field['required'] ?? false),
            // Dateifelder sind weder eindeutig noch durchsuchbar noch sortierbar.
            'is_unique' => $type !== 'file' && (bool) ($field['unique'] ?? false),
            'is_searchable' => $type !== 'file' && (bool) ($field['searchable'] ?? false),
            'is_sortable' => $type !== 'file' && (bool) ($field['sortable'] ?? false),
            'show_in_table' => true,
            'show_in_form' => true,
        ];
    }

    /**
     * Feldregeln aus dem Text des Assistenten; Ungültiges meldet TableAssistant (hier leer).
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(mixed $text): array
    {
        try {
            return RuleText::merge([], RuleText::fieldRules((string) $text));
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /** @return array<string, string> */
    public static function cleanTranslations(mixed $value): array
    {
        return array_filter(array_map(fn (mixed $v): string => trim((string) $v), array_intersect_key((array) $value, array_flip(self::locales()))), static fn (string $v): bool => $v !== '');
    }

    /** @return array<string, mixed> */
    public static function field(): array
    {
        return self::object([
            'name' => ['type' => 'string'],
            'label' => self::translations(),
            'type' => ['type' => 'string', 'enum' => self::TYPES],
            'required' => ['type' => 'boolean'],
            'unique' => ['type' => 'boolean'],
            'searchable' => ['type' => 'boolean'],
            'sortable' => ['type' => 'boolean'],
            // Einfache Werte statt „oder null“ (Grammatik-Grenze von Anthropic): 0 bzw. "" = trifft nicht zu.
            'length' => ['type' => 'integer'],
            'precision' => ['type' => 'integer'],
            'scale' => ['type' => 'integer'],
            'choices' => ['type' => 'array', 'items' => self::choice()],
            'relation_table' => ['type' => 'string'],
            'relation_display' => ['type' => 'array', 'items' => ['type' => 'string']],
            'file_kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [...array_keys(FileKinds::MIMES), FileKinds::ANY]]],
            'max_mb' => ['type' => 'integer'],
            'max_files' => ['type' => 'integer'],
            // Feldregeln als kurzer Text (RuleText), z. B. „min=1; max=3“; leer = keine.
            'rules' => ['type' => 'string'],
            // Formel eines berechneten Felds (B-122); "" bei anderen Typen.
            'formula' => ['type' => 'string'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function choice(): array
    {
        return self::object(['value' => ['type' => 'string'], 'label' => self::translations()]);
    }

    /** @return array<string, mixed> Bezeichnung in allen Sprachen */
    public static function translations(): array
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
    public static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
