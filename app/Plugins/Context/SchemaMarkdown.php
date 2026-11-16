<?php

declare(strict_types=1);

namespace App\Plugins\Context;

use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\NowDefault;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Validation\FieldRuleCheck;

/** schema.md des Entwickler-Kontexts: das Schema aus SchemaExporter kompakt und lesbar (Englisch). */
final class SchemaMarkdown
{
    /** @param  array<string, mixed>  $schema  Ergebnis von SchemaExporter::export() */
    public function markdown(array $schema): string
    {
        $out = [
            '# Business tables (table designer)',
            '',
            'SAFT '.$schema['saft'].', plugin API '.$schema['api'].', languages: '.implode(', ', $schema['locales']).'.',
            'Exact definitions: `schema.json` (format `'.$schema['format'].'`). Access in plugins only through',
            '`Saft::table(\'<name>\')`; field names below are the technical names used in values and queries.',
            'Data permissions are named `data.<table>.<action>` (view, create, update, delete, export, import).',
            '',
        ];

        return implode("\n", $out)."\n".$this->tables($schema);
    }

    /**
     * Nur die Tabellen, ohne Kopf mit Hinweisen für Plugins – z. B. für den Hilfe-Assistenten.
     *
     * @param  array<string, mixed>  $schema  Ergebnis von SchemaExporter::export()
     */
    public function tables(array $schema): string
    {
        $out = [];

        foreach ($schema['tables'] as $table) {
            $out = [...$out, ...$this->table($table)];
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private function table(array $table): array
    {
        $lines = ['## `'.$table['name'].'` – '.self::label($table['label'])];
        $lines[] = '';

        if (! empty($table['external'])) {
            $lines[] = '*Connected external table: read-only through the plugin API. Key: '.implode(', ', (array) $table['key_columns']).'.*';
        } elseif (! $table['is_active']) {
            $lines[] = '*Inactive (hidden from users).*';
        }

        if (! empty($table['description'])) {
            $lines[] = self::label($table['description']);
        }

        $lines[] = '';
        $lines[] = '| Field | Label | Type | Flags | Details |';
        $lines[] = '|---|---|---|---|---|';

        foreach ($table['fields'] as $field) {
            $flags = array_keys(array_filter([
                'required' => $field['is_required'] ?? false,
                'unique' => $field['is_unique'] ?? false,
                'inactive' => ! ($field['is_active'] ?? true),
            ]));
            $lines[] = '| `'.$field['name'].'` | '.self::cell(self::label($field['label'])).' | '.$field['type'].' | '.implode(', ', $flags).' | '.self::cell(self::details($field)).' |';
        }

        $lines[] = '';

        if (! empty($table['rules'])) {
            $lines[] = 'Table rules (checked on save):';

            foreach ($table['rules'] as $rule) {
                $right = $rule['right_kind'] === 'field' ? '`'.$rule['right_field'].'`' : json_encode($rule['right_date'] ?? $rule['right_value'], JSON_UNESCAPED_UNICODE);
                $lines[] = '- `'.$rule['left'].'` '.$rule['operator'].' '.$right.(! empty($rule['when_filled']) ? ' (only when filled)' : '');
            }

            $lines[] = '';
        }

        if (! empty($table['details'])) {
            $lines[] = 'Detail tables shown with a record: '.implode(', ', array_map(static fn (string $d): string => '`'.$d.'`', $table['details'])).'.';
            $lines[] = '';
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $field */
    private static function details(array $field): string
    {
        $options = (array) ($field['options'] ?? []);
        $rules = (array) ($field['validation_rules'] ?? []);
        $parts = [];

        $parts[] = match ($field['type']) {
            'string' => 'max '.($options['length'] ?? 255).' chars',
            'decimal' => 'precision '.($options['precision'] ?? '?').', scale '.($options['scale'] ?? '?'),
            'select' => 'values: '.implode(', ', array_map(static fn (array $o): string => '`'.$o['value'].'`', array_filter((array) ($options['options'] ?? []), 'is_array'))),
            'relation' => '→ `'.($options['table'] ?? '?').'` (shows '.implode(', ', (array) ($options['display_fields'] ?? [$options['display_field'] ?? 'id'])).')',
            'computed' => 'formula `'.($options['formula'] ?? '').'` → '.($options['result'] ?? 'decimal').', calculated on save, read-only',
            default => '',
        };

        foreach (['min_length' => 'min length', 'max_length' => 'max length', 'min' => 'min', 'max' => 'max'] as $key => $label) {
            if (isset($rules[$key])) {
                $parts[] = $label.' '.$rules[$key];
            }
        }

        foreach (['email', 'url'] as $key) {
            if (! empty($rules[$key])) {
                $parts[] = 'must be '.($key === 'email' ? 'an email address' : 'a URL');
            }
        }

        if (! empty($rules[FieldRuleCheck::OPTION])) {
            $parts[] = 'plugin checks: '.implode(', ', (array) $rules[FieldRuleCheck::OPTION]);
        }

        if (! empty($options[CopySource::OPTION])) {
            $parts[] = 'copied from `'.$options[CopySource::OPTION].'` on entry';
        }

        if (! empty($options[SequenceSuggester::OPTION])) {
            $parts[] = 'next number suggested';
        }

        if (! empty($options[NowDefault::OPTION])) {
            $parts[] = 'prefilled with the current date/time on entry';
        }

        return implode('; ', array_filter($parts));
    }

    /** Bezeichnung in Englisch, sonst Standardsprache, mit der Standardsprache in Klammern, wenn sie abweicht. */
    private static function label(mixed $translations): string
    {
        $translations = array_filter((array) $translations);
        $default = (string) ($translations[config('locales.default')] ?? reset($translations) ?: '');
        $english = (string) ($translations['en'] ?? '');

        return $english === '' || $english === $default ? $default : $english.' ('.$default.')';
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
