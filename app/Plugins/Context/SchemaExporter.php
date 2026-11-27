<?php

declare(strict_types=1);

namespace App\Plugins\Context;

use App\DynamicData\DataPermissions;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Plugins\Api\ApiManifest;
use App\Support\AppLocale;

/**
 * schema.json des Entwickler-Kontexts: Tabellen des Table-Designers im Eingabeformat von DynamicSchemaService
 * (createTable/addField/updateTable), damit PluginTestCase::createTablesFromSchema() sie nachbauen kann. Angebundene
 * Tabellen nur zur Information (external, ohne Verbindungsangaben). Keine Datensätze, keine Zugangsdaten.
 */
final class SchemaExporter
{
    public const string FORMAT = 'saft-schema/1';

    /** Feldeigenschaften, die exportiert werden (wie DynamicSchemaService::addField). */
    private const array FIELD_KEYS = ['is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form', 'is_active', 'sort_order'];

    /**
     * @param  list<string>|null  $only  technische Namen (null = alle)
     * @return array<string, mixed>
     */
    public function export(?array $only = null, bool $external = true): array
    {
        $tables = CustomTable::query()->with('fields')->orderBy('name')->get()
            ->filter(static fn (CustomTable $t): bool => ($only === null || in_array($t->name, $only, true)) && ($external || ! $t->isExternal()));

        return [
            'format' => self::FORMAT,
            'saft' => (string) config('saft.version'),
            'api' => ApiManifest::current(),
            'locales' => AppLocale::available(),
            'tables' => $tables->map(fn (CustomTable $t): array => $this->table($t))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function table(CustomTable $table): array
    {
        $fields = $table->fields->sortBy('sort_order')->values();

        $data = [
            'name' => $table->name,
            'label' => $table->getTranslations('label'),
            'description' => $table->getTranslations('description') ?: null,
            'icon' => $table->icon,
            'is_active' => (bool) $table->is_active,
        ];

        if ($table->isExternal()) {
            // Nur lesbar über die Plugin-API; Verbindung, Schema und Quelltabelle bleiben draussen.
            return [...$data, 'external' => true, 'key_columns' => $table->keyColumns(), 'fields' => $fields->map(static fn (CustomField $f): array => [
                'name' => $f->name,
                'label' => $f->getTranslations('label'),
                'type' => $f->type->value,
                'is_active' => (bool) $f->is_active,
            ])->all()];
        }

        return [
            ...$data,
            'export_enabled' => (bool) $table->export_enabled,
            'import_enabled' => (bool) $table->import_enabled,
            'bulk_enabled' => (bool) $table->bulk_enabled,
            'permissions' => DataPermissions::names($table->name),
            'fields' => $fields->map(static fn (CustomField $f): array => [
                'name' => $f->name,
                'label' => $f->getTranslations('label'),
                'type' => $f->type->value,
                'options' => $f->optionsArray() ?: null,
                'validation_rules' => (array) ($f->validation_rules ?? []) ?: null,
                ...$f->only(self::FIELD_KEYS),
            ])->all(),
            'rules' => TableRuleNormalizer::toRows($table),
            // Detailtabellen als „tabelle.verknüpfungsfeld“ statt IDs (die gibt es in einer anderen Installation nicht).
            'details' => $this->details(array_map('intval', (array) ($table->detail_field_ids ?? []))),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<string> „tabelle.feld“ in der gespeicherten Reihenfolge
     */
    private function details(array $ids): array
    {
        $fields = CustomField::query()->with('custom_table')->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(
            static fn (int $id): ?string => isset($fields[$id]) ? $fields[$id]->custom_table?->name.'.'.$fields[$id]->name : null,
            $ids,
        )));
    }
}
