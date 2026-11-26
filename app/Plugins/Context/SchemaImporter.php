<?php

declare(strict_types=1);

namespace App\Plugins\Context;

use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Services\DynamicSchemaService;
use InvalidArgumentException;

/**
 * Legt Tabellen aus einer schema.json (SchemaExporter) über den Table-Designer an – für Plugin-Tests
 * (PluginTestCase::createTablesFromSchema) und später den Konfigurationstransport. In Durchgängen, damit Verweise
 * aufgehen: einfache Felder, Verknüpfungen (auch gegenseitige), Übernahmen, berechnete Felder (in passender
 * Reihenfolge), Tabellenregeln und Detailtabellen, zuletzt Deaktivierungen. Angebundene Tabellen werden übergangen.
 */
final readonly class SchemaImporter
{
    public function __construct(private DynamicSchemaService $service) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $only  nur diese Tabellen (leer = alle)
     * @param  App|int|null  $app  Ziel-App (B-107); ohne Angabe die einzige vorhandene
     * @return array<string, CustomTable> angelegte Tabellen nach Name
     */
    public function import(array $schema, array $only = [], App|int|null $app = null): array
    {
        $appId = App::resolve($app)->id;

        if (($schema['format'] ?? null) !== SchemaExporter::FORMAT) {
            throw new InvalidArgumentException('Unbekanntes Schemaformat: '.json_encode($schema['format'] ?? null).' (erwartet '.SchemaExporter::FORMAT.').');
        }

        $tables = array_values(array_filter((array) ($schema['tables'] ?? []), static fn (mixed $t): bool => is_array($t)
            && empty($t['external']) && ($only === [] || in_array($t['name'] ?? null, $only, true))));
        $created = [];
        $later = [];

        // 1. Tabellen mit einfachen Feldern.
        foreach ($tables as $table) {
            [$simple, $later[$table['name']]] = $this->split((array) ($table['fields'] ?? []));
            $created[$table['name']] = $this->service->createTable([
                'app_id' => $appId,
                'name' => $table['name'],
                'label' => $table['label'] ?? $table['name'],
                'description' => $table['description'] ?? null,
                'icon' => $table['icon'] ?? null,
                'export_enabled' => (bool) ($table['export_enabled'] ?? false),
                'import_enabled' => (bool) ($table['import_enabled'] ?? false),
            ], $simple);
        }

        // 2. Verknüpfungen, dann Übernahmen (brauchen die Verknüpfung), dann berechnete Felder.
        foreach (['relation', 'copy'] as $kind) {
            foreach ($later as $name => $fields) {
                foreach ($fields[$kind] as $field) {
                    $this->service->addField($created[$name]->refresh(), $field);
                }
            }
        }

        $this->computed($created, array_map(static fn (array $f): array => $f['computed'], $later));

        // 3. Tabellenregeln, Detailtabellen, Deaktivierungen.
        foreach ($tables as $table) {
            $model = $created[$table['name']]->refresh();
            $update = [];

            if (! empty($table['rules'])) {
                $update['validation_rules'] = $table['rules'];
            }

            if (! empty($table['details'])) {
                $update['detail_field_ids'] = $this->detailIds((array) $table['details']);
            }

            if (array_key_exists('is_active', $table) && ! $table['is_active']) {
                $update['is_active'] = false;
            }

            if ($update !== []) {
                $this->service->updateTable($model, $update);
            }
        }

        return array_map(static fn (CustomTable $t): CustomTable => $t->refresh(), $created);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array{0: list<array<string, mixed>>, 1: array{relation: list<array<string, mixed>>, copy: list<array<string, mixed>>, computed: list<array<string, mixed>>}}
     */
    private function split(array $fields): array
    {
        $simple = [];
        $numbers = [];
        $later = ['relation' => [], 'copy' => [], 'computed' => []];

        foreach ($fields as $field) {
            $field = array_filter($field, static fn (mixed $v): bool => $v !== null);
            $kind = match (true) {
                ($field['type'] ?? null) === 'relation' => 'relation',
                ($field['type'] ?? null) === 'computed' => 'computed',
                // Nummernkreis mit Bezugsdatum bzw. Gruppe (B-135, N4): nach dem Datums- bzw. Auswahlfeld, aber vor den
                // Verknüpfungen – eine Verknüpfung darf die Nummer anzeigen (Rechnungsposition → Rechnung).
                ! empty($field['options'][NumberPattern::DATE_FIELD]) || ! empty($field['options'][NumberPattern::GROUP_FIELD]) => 'number',
                // „Übernehmen aus“ und „Zählen je“ (Vorschlag der nächsten Nummer je Verknüpfung) brauchen die Verknüpfung.
                ! empty($field['options'][CopySource::OPTION]), ! empty($field['options'][SequenceSuggester::SCOPE]) => 'copy',
                default => null,
            };

            match ($kind) {
                null => $simple[] = $field,
                'number' => $numbers[] = $field,
                default => $later[$kind][] = $field,
            };
        }

        // Ans Ende der einfachen Felder, wenn Datums- und Gruppenfeld solche sind; sonst (z. B. Datum selbst übernommen) später.
        $names = array_column($simple, 'name');

        foreach ($numbers as $field) {
            $needs = array_filter([$field['options'][NumberPattern::DATE_FIELD] ?? null, $field['options'][NumberPattern::GROUP_FIELD] ?? null]);
            array_diff($needs, $names) === [] ? $simple[] = $field : $later['copy'][] = $field;
        }

        return [$simple, $later];
    }

    /**
     * Berechnete Felder können auf berechnete Felder anderer Tabellen aufbauen: so lange wiederholen, bis alle angelegt
     * sind oder sich nichts mehr bewegt (dann die letzte Meldung).
     *
     * @param  array<string, CustomTable>  $tables
     * @param  array<string, list<array<string, mixed>>>  $pending
     */
    private function computed(array $tables, array $pending): void
    {
        while (($open = array_filter($pending)) !== []) {
            $progress = false;
            $error = null;

            foreach ($open as $name => $fields) {
                foreach ($fields as $index => $field) {
                    try {
                        $this->service->addField($tables[$name]->refresh(), $field);
                        unset($pending[$name][$index]);
                        $progress = true;
                    } catch (BusinessRuleException $e) {
                        $error = $e;
                    }
                }
            }

            if (! $progress && $error !== null) {
                throw $error;
            }
        }
    }

    /**
     * @param  list<string>  $details  „tabelle.verknüpfungsfeld“
     * @return list<int>
     */
    private function detailIds(array $details): array
    {
        $ids = [];

        foreach ($details as $detail) {
            [$table, $field] = explode('.', (string) $detail, 2) + [1 => ''];
            $id = CustomField::query()->where('name', $field)->whereHas('custom_table', static fn ($q) => $q->where('name', $table))->value('id');

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
