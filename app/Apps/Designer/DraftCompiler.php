<?php

declare(strict_types=1);

namespace App\Apps\Designer;

use App\Apps\AppInterfaces;
use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppInstaller;
use App\DynamicData\Assistant\RuleText;
use App\DynamicData\Assistant\TableProposalSchema;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaCheck;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Filament\Support\TableNameRule;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Plugins\Api\ApiManifest;
use App\Support\IconCatalog;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Vorschlag des KI-App-Designers → App-Definition saft-app/1 (B-107, Etappe 7a). Prüft den Vorschlag vollständig gegen
 * sich selbst und die Installation, bevor ihn jemand sieht: Präfix, Namen, Felder, Verknüpfungen (im Entwurf oder auf
 * freigegebene Tabellen anderer Apps), Formeln samt Summen über Kind-Tabellen des Entwurfs, Regeln, Menüs – und zuletzt
 * mit der Installationsanalyse. Ergänzt Detailtabellen (je Verknüpfung im Entwurf), Menü mit Datenmodell und eine
 * Fachrolle mit allen Rechten auf die neuen Tabellen.
 */
final readonly class DraftCompiler
{
    public function __construct(
        private FieldDefinitionNormalizer $normalizer,
        private FormulaCheck $formulas,
        private AppInstaller $installer,
    ) {}

    /**
     * @param  array<string, mixed>  $proposal  Antwort der KI (DesignerSchema)
     * @return array{definition: array<string, mixed>|null, problems: list<string>, warnings: list<string>}
     */
    public function compile(array $proposal): array
    {
        $problems = [];
        $app = (array) ($proposal['app'] ?? []);
        $prefix = strtolower(trim((string) ($app['prefix'] ?? '')));
        $name = TableProposalSchema::cleanTranslations($app['name'] ?? null);

        if ($name === []) {
            $problems[] = __('app_designer.errors.app_name');
        }

        if (preg_match('/^[a-z][a-z0-9]{0,19}$/', $prefix) !== 1) {
            $problems[] = __('app_designer.errors.prefix', ['prefix' => $prefix]);
        } elseif (App::query()->where('prefix', $prefix)->exists()) {
            $problems[] = __('app_designer.errors.prefix_taken', ['prefix' => $prefix]);
        }

        $icon = IconCatalog::fromAssistant($app['icon'] ?? null);

        if (filled($app['icon'] ?? null) && $icon === null) {
            $problems[] = __('tables_assistant.errors.icon', ['icon' => (string) $app['icon']]);
        }

        $tables = array_values(array_filter((array) ($proposal['tables'] ?? []), 'is_array'));

        if ($tables === []) {
            $problems[] = __('app_designer.errors.no_tables');
        }

        // Felder je Tabelle als Formularzustand des Table-Designers.
        $forms = [];

        foreach ($tables as $table) {
            $tableName = (string) ($table['name'] ?? '');
            $problems = [...$problems, ...$this->tableNameProblems($tableName, $prefix, array_keys($forms))];
            $forms[$tableName] = array_map(TableProposalSchema::toFormField(...), array_values(array_filter((array) ($table['fields'] ?? []), 'is_array')));

            if (filled($table['icon'] ?? null) && IconCatalog::fromAssistant($table['icon']) === null) {
                $problems[] = __('tables_assistant.errors.icon', ['icon' => (string) $table['icon']]);
            }
        }

        $virtual = $this->virtualTables($tables, $forms);

        foreach ($tables as $table) {
            $tableName = (string) ($table['name'] ?? '');
            $problems = [...$problems, ...$this->fieldProblems($tableName, (array) ($table['fields'] ?? []), $forms, $prefix)];
        }

        // Formeln: Summen über Kind-Tabellen des Entwurfs, die es noch nicht gibt.
        $children = $this->children($virtual);

        foreach ($forms as $tableName => $fields) {
            $problems = [...$problems, ...FormulaAnalyzer::withChildren($children, fn (): array => $this->formulas->problems($fields, [], $tableName, null))];
        }

        $rules = [];

        foreach ($tables as $table) {
            $tableName = (string) ($table['name'] ?? '');

            if (! isset($virtual[$tableName])) {
                continue;
            }

            try {
                $rules[$tableName] = $this->tableRules($virtual[$tableName], array_values(array_filter(array_map('strval', (array) ($table['rules'] ?? [])), 'filled')));
            } catch (BusinessRuleException $e) {
                $problems[] = __('tables_assistant.errors.table_rule', ['error' => $tableName.': '.$e->getMessage()]);
            }
        }

        $menus = array_values(array_filter((array) ($proposal['menus'] ?? []), 'is_array'));
        $problems = [...$problems, ...$this->menuProblems($menus, array_keys($forms))];

        if ($problems !== []) {
            return ['definition' => null, 'problems' => array_values(array_unique($problems)), 'warnings' => []];
        }

        $definition = $this->definition($name, TableProposalSchema::cleanTranslations($app['description'] ?? null), $prefix, $icon, $tables, $forms, $rules, $menus);
        $analysis = $this->installer->analyze($definition);

        return ['definition' => $definition, 'problems' => $analysis->blockers, 'warnings' => $analysis->warnings];
    }

    /**
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function tableNameProblems(string $name, string $prefix, array $seen): array
    {
        $problems = [];
        $fail = function (string $message) use (&$problems): void {
            $problems[] = $message;
        };
        FieldForm::identifierRule(table: true)()('name', $name, $fail);

        if ($prefix !== '' && ! str_starts_with($name, $prefix.'_')) {
            $problems[] = __('app_designer.errors.table_prefix', ['table' => $name, 'prefix' => $prefix.'_']);
        }

        if (in_array($name, $seen, true)) {
            $problems[] = __('app_designer.errors.duplicate_table', ['table' => $name]);
        } elseif ($name !== '' && ($owner = TableNameRule::takenBy($name)) !== null) {
            $problems[] = __('tables_assistant.errors.name_taken', ['name' => $name, 'owner' => $owner]);
        }

        return $problems;
    }

    /**
     * Felder einer Tabelle: Name, Typ, Optionen (wie beim Anlegen), Feldregeln, Verknüpfungen.
     *
     * @param  list<mixed>  $fields  Antwort der KI
     * @param  array<string, list<array<string, mixed>>>  $forms
     * @return list<string>
     */
    private function fieldProblems(string $table, array $fields, array $forms, string $prefix): array
    {
        $problems = [];
        $names = [];

        foreach (array_values(array_filter($fields, 'is_array')) as $i => $field) {
            $form = $forms[$table][$i];
            $label = $table.'.'.(string) $form['name'];

            if (in_array($form['name'], $names, true)) {
                $problems[] = __('tables_assistant.errors.duplicate_field', ['name' => $label]);
            }

            $names[] = $form['name'];

            try {
                RuleText::fieldRules((string) ($field['rules'] ?? ''));
            } catch (InvalidArgumentException $e) {
                $problems[] = __('tables_assistant.errors.rule', ['name' => $label, 'rule' => $e->getMessage()]);
            }

            if ($form['type'] === FieldType::Relation->value) {
                $problems = [...$problems, ...$this->relationProblems($label, $form, $forms)];

                continue;
            }

            // Formeln prüft FormulaCheck gegen die Felder des Entwurfs; hier Name, Typ und Optionen.
            if ($form['type'] === FieldType::Computed->value) {
                $form['options'] = [...(array) $form['options'], 'formula' => '0'];
            }

            try {
                $this->normalizer->normalize(FieldForm::toServiceData($form));
            } catch (BusinessRuleException $e) {
                $problems[] = __('tables_assistant.errors.field', ['name' => $label, 'error' => $e->getMessage()]);
            }
        }

        return $problems;
    }

    /**
     * Verknüpfung auf eine Tabelle des Entwurfs oder auf eine freigegebene Tabelle einer anderen App.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, list<array<string, mixed>>>  $forms
     * @return list<string>
     */
    private function relationProblems(string $label, array $form, array $forms): array
    {
        $target = (string) ($form['options']['table'] ?? '');
        $display = RelationDisplay::fields((array) $form['options']);

        if ($display === []) {
            return [__('tables.errors.unknown_display_field', ['field' => '', 'table' => $target])];
        }

        if (isset($forms[$target])) {
            $fields = collect($forms[$target])->keyBy('name');

            foreach ($display as $name) {
                if (! isset($fields[$name]) || $fields[$name]['type'] === FieldType::Relation->value) {
                    return [__('tables_assistant.errors.field', ['name' => $label, 'error' => __('tables.errors.unknown_display_field', ['field' => $name, 'table' => $target])])];
                }
            }

            return [];
        }

        $existing = CustomTable::query()->designer()->with('fields')->where('name', $target)->first();

        if ($existing === null) {
            return [__('tables_assistant.errors.field', ['name' => $label, 'error' => __('tables.errors.unknown_target', ['table' => $target])])];
        }

        // Eine neue App: Jede vorhandene Tabelle gehört zu einer anderen App – nur Schnittstellen.
        if (! $existing->is_shared) {
            return [__('tables_assistant.errors.relation_app', ['name' => $label, 'table' => $target])];
        }

        $unshared = array_values(array_filter($display, static fn (string $f): bool => ! AppInterfaces::fieldShared($existing, $f)));

        return $unshared === [] ? [] : [__('tables_assistant.errors.relation_shared_fields', ['name' => $label, 'table' => $target, 'fields' => implode(', ', $unshared)])];
    }

    /**
     * Tabellen des Entwurfs als nicht gespeicherte Modelle (für Regeln und Formeln).
     *
     * @param  list<array<string, mixed>>  $tables
     * @param  array<string, list<array<string, mixed>>>  $forms
     * @return array<string, CustomTable>
     */
    private function virtualTables(array $tables, array $forms): array
    {
        $virtual = [];

        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name === '' || ! isset($forms[$name])) {
                continue;
            }

            $model = new CustomTable(['name' => $name, 'label' => TableProposalSchema::cleanTranslations($table['label'] ?? null) ?: $name]);
            $model->setRelation('fields', collect($forms[$name])
                ->filter(static fn (array $f): bool => FieldType::tryFrom((string) $f['type']) !== null)
                ->map(static fn (array $f): CustomField => new CustomField([
                    'name' => (string) $f['name'], 'type' => FieldType::from((string) $f['type']), 'label' => $f['label'] ?: [],
                    'options' => $f['options'] ?? [], 'is_active' => true, 'is_unique' => (bool) ($f['is_unique'] ?? false),
                ]))->values());
            $virtual[$name] = $model;
        }

        return $virtual;
    }

    /**
     * Kind-Tabellen je Tabelle des Entwurfs: Tabellen mit Verknüpfungen auf sie.
     *
     * @param  array<string, CustomTable>  $virtual
     * @return array<string, array<string, array{table: CustomTable, relations: list<string>}>>
     */
    private function children(array $virtual): array
    {
        $children = [];

        foreach ($virtual as $child) {
            foreach ($child->fields->where('type', FieldType::Relation) as $relation) {
                $target = (string) ($relation->optionsArray()['table'] ?? '');

                if (isset($virtual[$target])) {
                    $children[$target][$child->name] ??= ['table' => $child, 'relations' => []];
                    $children[$target][$child->name]['relations'][] = $relation->name;
                }
            }
        }

        return $children;
    }

    /**
     * @param  list<string>  $texts
     * @return list<array<string, mixed>> Regeln als Zeilen (wie im Table-Designer)
     *
     * @throws BusinessRuleException
     */
    private function tableRules(CustomTable $table, array $texts): array
    {
        $rows = [];

        foreach ($texts as $text) {
            try {
                $rows[] = RuleText::tableRow($text, $table);
            } catch (InvalidArgumentException) {
                throw new BusinessRuleException(__('tables_assistant.errors.table_rule_text', ['rule' => $text]));
            }
        }

        if ($rows !== []) {
            app(TableRuleNormalizer::class)->normalize($table, $rows);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $menus
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function menuProblems(array $menus, array $tables): array
    {
        $problems = [];
        $seen = [];

        foreach ($menus as $menu) {
            if (TableProposalSchema::cleanTranslations($menu['label'] ?? null) === []) {
                $problems[] = __('app_designer.errors.menu_label');
            }

            if (filled($menu['icon'] ?? null) && IconCatalog::fromAssistant($menu['icon']) === null) {
                $problems[] = __('tables_assistant.errors.icon', ['icon' => (string) $menu['icon']]);
            }

            foreach (array_map('strval', (array) ($menu['tables'] ?? [])) as $table) {
                if (! in_array($table, $tables, true)) {
                    $problems[] = __('app_designer.errors.menu_table', ['table' => $table]);
                } elseif (in_array($table, $seen, true)) {
                    $problems[] = __('app_designer.errors.menu_twice', ['table' => $table]);
                }

                $seen[] = $table;
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     * @param  list<array<string, mixed>>  $tables
     * @param  array<string, list<array<string, mixed>>>  $forms
     * @param  array<string, list<array<string, mixed>>>  $rules
     * @param  list<array<string, mixed>>  $menus
     * @return array<string, mixed>
     */
    private function definition(array $name, array $description, string $prefix, ?string $icon, array $tables, array $forms, array $rules, array $menus): array
    {
        $key = static fn (): string => (string) Str::uuid();
        $default = (string) config('locales.default');
        $details = [];

        // Detailtabellen: Unter jeder Tabelle stehen die Tabellen des Entwurfs, die auf sie verweisen.
        foreach ($forms as $tableName => $fields) {
            foreach ($fields as $field) {
                $target = (string) ($field['options']['table'] ?? '');

                if ($field['type'] === FieldType::Relation->value && isset($forms[$target]) && $target !== $tableName) {
                    $details[$target][] = $tableName.'.'.$field['name'];
                }
            }
        }

        $definitionTables = array_map(static fn (array $table): array => [
            'key' => $key(),
            'name' => (string) $table['name'],
            'label' => TableProposalSchema::cleanTranslations($table['label'] ?? null) ?: (string) $table['name'],
            'description' => TableProposalSchema::cleanTranslations($table['description'] ?? null) ?: null,
            'icon' => IconCatalog::fromAssistant($table['icon'] ?? null),
            'is_active' => true,
            'export_enabled' => true,
            'import_enabled' => true,
            'bulk_enabled' => true,
            'fields' => array_map(static fn (array $form): array => ['key' => $key(), ...FieldForm::toServiceData($form)], $forms[(string) $table['name']]),
            'rules' => $rules[(string) $table['name']] ?? [],
            'details' => $details[(string) $table['name']] ?? [],
        ], $tables);

        $labels = collect($definitionTables)->mapWithKeys(static fn (array $t): array => [$t['name'] => $t]);
        // Menüpfade in der Vorgabesprache (wie beim Export).
        $pick = static fn (array|string $value, string $fallback): string => is_array($value) ? (string) ($value[$default] ?? reset($value) ?: $fallback) : $value;
        $root = $pick($name, $prefix);
        $entry = static fn (string $parent, string $table, int $order) => [
            'key' => $key(),
            'path' => $parent.' › '.$pick($labels[$table]['label'], $table),
            'parent' => $parent,
            'label' => $labels[$table]['label'],
            'icon' => $labels[$table]['icon'],
            'permission_name' => DataPermissions::name($table, 'view'),
            'sort_order' => $order,
            'is_active' => true,
            'target' => ['type' => 'data', 'table' => $table, 'columns' => []],
        ];
        $menuEntries = [[
            'key' => $key(), 'path' => $root, 'parent' => null, 'label' => $name, 'icon' => $icon, 'permission_name' => null,
            'sort_order' => ((int) App::query()->max('sort_order')) + 10, 'is_active' => true, 'target' => ['type' => 'group'],
        ]];
        $placed = [];

        foreach ($menus as $i => $menu) {
            $label = TableProposalSchema::cleanTranslations($menu['label'] ?? null);
            $path = $root.' › '.$pick($label, (string) ($i + 1));
            $menuEntries[] = [
                'key' => $key(), 'path' => $path, 'parent' => $root, 'label' => $label, 'icon' => IconCatalog::fromAssistant($menu['icon'] ?? null),
                'permission_name' => null, 'sort_order' => ($i + 1) * 10, 'is_active' => true, 'target' => ['type' => 'group'],
            ];

            foreach (array_values(array_map('strval', (array) ($menu['tables'] ?? []))) as $j => $table) {
                $menuEntries[] = $entry($path, $table, ($j + 1) * 10);
                $placed[] = $table;
            }
        }

        // Tabellen ohne Menügruppe direkt in die Gruppe der App, danach das Datenmodell.
        $order = (count($menus) + 1) * 10;

        foreach (array_keys($forms) as $table) {
            if (! in_array($table, $placed, true)) {
                $menuEntries[] = $entry($root, $table, $order);
                $order += 10;
            }
        }

        $modelPath = $root.' › '.$pick(DataMenu::DATA_MODEL_LABELS, 'Datenmodell');
        $menuEntries[] = [
            'key' => $key(), 'path' => $modelPath, 'parent' => $root, 'label' => DataMenu::DATA_MODEL_LABELS, 'icon' => 'heroicon-o-share',
            'permission_name' => null, 'sort_order' => $order + 50, 'is_active' => true,
            'target' => ['type' => 'route', 'route' => DataModelPage::ROUTE, 'parameters' => ['menu' => 'menu:'.$modelPath]],
        ];

        $permissions = ['reports.view'];

        foreach (array_keys($forms) as $table) {
            $permissions = [...$permissions, ...DataPermissions::names($table)];
        }

        return [
            'format' => AppDefinition::FORMAT,
            'saft' => (string) config('saft.version'),
            'api' => ApiManifest::current(),
            'exported_at' => now()->toIso8601String(),
            'parts' => ['roles'],
            'app' => [
                'key' => $key(), 'name' => $name, 'description' => $description ?: null, 'icon' => $icon, 'prefix' => $prefix,
                'version' => null, 'interface_version' => 1, 'manual' => [],
            ],
            'plugins' => [],
            'tables' => $definitionTables,
            'external_tables' => [],
            'report_links' => [],
            'reports' => [],
            'menus' => $menuEntries,
            // Fachrolle mit allen Rechten auf die neuen Tabellen und dem Ansehen von Auswertungen.
            'roles' => [['key' => $key(), 'name' => $root, 'permissions' => $permissions, 'hidden_menus' => []]],
            'tasks' => [],
            'requires' => [],
        ];
    }
}
