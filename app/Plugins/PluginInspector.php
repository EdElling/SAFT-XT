<?php

declare(strict_types=1);

namespace App\Plugins;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\Parser;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Validation\FieldRuleCheck;
use App\Models\CustomField;
use App\Plugins\Api\ApiManifest;
use App\Plugins\Api\DashboardWidget;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\Api\Manual;
use Closure;
use Composer\InstalledVersions;
use Filament\Widgets\Widget;
use FilesystemIterator;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Prüft ein Plugin vor der Auslieferung (saft:plugins --check): Kennung, Angaben in der composer.json, Kompatibilität,
 * Rechte, Menüvorschläge, Abhängigkeiten und dass der Code von SAFT nur die Plugin-API verwendet.
 */
final readonly class PluginInspector
{
    public const string OK = 'ok';

    public const string WARNING = 'warning';

    public const string ERROR = 'error';

    private const string PERMISSION_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    public function __construct(private PluginRegistry $plugins) {}

    /** @return list<array{level: string, message: string}> */
    public function inspect(SaftPlugin $plugin): array
    {
        $results = [];
        $add = static function (string $level, string $key, array $replace = []) use (&$results): void {
            $results[] = ['level' => $level, 'message' => __('plugins.check.'.$key, $replace)];
        };

        preg_match(SaftPlugin::ID_PATTERN, $plugin->id())
            ? $add(self::OK, 'id_ok', ['id' => $plugin->id()])
            : $add(self::ERROR, 'id_invalid', ['id' => $plugin->id()]);

        $plugin->version() !== ''
            ? $add(self::OK, 'version_ok', ['version' => $plugin->version()])
            : $add(self::WARNING, 'version_missing');

        match (true) {
            $plugin->requiresApi() === null => $add(self::ERROR, 'api_missing'),
            $plugin->isCompatible() === false => $add(self::ERROR, 'incompatible', [
                'requires' => $plugin->requiresApi(), 'provided' => implode(', ', ApiManifest::versions()),
            ]),
            default => $add(self::OK, 'compatible', ['requires' => $plugin->requiresApi()]),
        };

        $before = count($results);

        foreach (array_keys($plugin->permissions()) as $action) {
            if (! preg_match(self::PERMISSION_PATTERN, (string) $action)) {
                $add(self::ERROR, 'permission_invalid', ['name' => $action]);
            }
        }

        foreach ($plugin->menuSuggestions() as $menu) {
            if (! Route::has($menu->route)) {
                $add(self::ERROR, 'route_missing', ['route' => $menu->route]);
            }

            if ($menu->permission !== null && ! in_array($menu->permission, $plugin->permissionNames(), true) && ! $this->permissionExists($menu->permission)) {
                $add(self::ERROR, 'menu_permission_unknown', ['permission' => $menu->permission, 'route' => $menu->route]);
            }
        }

        $declared = array_keys($plugin->requiredTables());

        foreach ([...$plugin->recordListeners(), ...$plugin->dataActions()] as $extension) {
            if (! in_array($extension->table, $declared, true)) {
                $add(self::WARNING, 'table_undeclared', ['table' => $extension->table]);
                $declared[] = $extension->table;
            }
        }

        if (count($results) === $before) {
            $add(self::OK, 'definitions_ok', ['permissions' => count($plugin->permissions()), 'menus' => count($plugin->menuSuggestions())]);
        }

        $before = count($results);
        $this->inspectExtensions($plugin, $add);
        $counts = ['widgets' => count($this->widgets($plugin)), 'functions' => count($plugin->formulaFunctions()), 'rules' => count($plugin->fieldRules())];

        if (count($results) === $before && array_sum($counts) > 0) {
            $add(self::OK, 'extensions_ok', $counts);
        }

        $this->inspectManuals($plugin, $add);

        $missing = $plugin->missingDependencies();
        $missing === []
            ? $add(self::OK, 'dependencies_ok')
            : $add(self::WARNING, 'dependencies_missing', ['missing' => implode(', ', $missing)]);

        $violations = $this->apiViolations($plugin->basePath().'/src');
        $violations === []
            ? $add(self::OK, 'api_usage_ok')
            : array_map(static fn (string $violation) => $add(self::ERROR, 'api_usage', ['use' => $violation]), $violations);

        return $results;
    }

    /** Handbücher: gültige Kennung, Titel auf Deutsch und Englisch, Datei auf Englisch, fehlende Sprachen als Hinweis. */
    private function inspectManuals(SaftPlugin $plugin, Closure $add): void
    {
        foreach ($plugin->manuals() as $manual) {
            if (preg_match(Manual::ID_PATTERN, $manual->id) !== 1) {
                $add(self::ERROR, 'manual_id_invalid', ['id' => $manual->id]);

                continue;
            }

            $folder = $plugin->basePath().'/'.trim($manual->path, '/');
            $languages = array_values(array_filter(Manual::LANGUAGES, static fn (string $l): bool => is_file($folder.'/'.$l.'.md')));

            if (! in_array('en', $languages, true)) {
                $add(self::ERROR, 'manual_missing', ['id' => $manual->id, 'file' => trim($manual->path, '/').'/en.md']);

                continue;
            }

            if (! isset($manual->title['de'], $manual->title['en'])) {
                $add(self::WARNING, 'manual_title', ['id' => $manual->id]);
            }

            $add(self::OK, 'manual_ok', ['id' => $manual->id, 'languages' => implode(', ', $languages)]);
        }
    }

    /** Widgets, Formelfunktionen und Prüfregeln: Namen, Konflikte, Bezeichnungen. */
    private function inspectExtensions(SaftPlugin $plugin, Closure $add): void
    {
        foreach ($this->widgets($plugin) as $widget) {
            if ($widget::isDiscovered() && ! is_subclass_of($widget, DashboardWidget::class)) {
                $add(self::WARNING, 'widget_label_missing', ['widget' => class_basename($widget)]);
            }
        }

        $reserved = [...array_keys(FormulaAnalyzer::FUNCTIONS), ...FormulaAnalyzer::AGGREGATES, ...FormulaAnalyzer::KEYWORDS];
        $seen = [];

        foreach ($plugin->formulaFunctions() as $function) {
            $other = $this->functionOwner($function->name, $plugin);

            match (true) {
                preg_match(FormulaFunction::NAME_PATTERN, $function->name) !== 1 => $add(self::ERROR, 'function_invalid', ['name' => $function->name]),
                in_array($function->name, $reserved, true) => $add(self::ERROR, 'function_reserved', ['name' => $function->name]),
                isset($seen[$function->name]) => $add(self::ERROR, 'function_duplicate', ['name' => $function->name]),
                $other !== null => $add(self::ERROR, 'function_conflict', ['name' => $function->name, 'plugin' => $other]),
                default => null,
            };

            $seen[$function->name] = true;
        }

        $seen = [];

        foreach ($plugin->fieldRules() as $rule) {
            $types = array_diff($rule->types, FieldRule::TYPES);

            match (true) {
                preg_match(FieldRule::NAME_PATTERN, $rule->name) !== 1 => $add(self::ERROR, 'rule_invalid', ['name' => $rule->name]),
                $rule->types === [] || $types !== [] => $add(self::ERROR, 'rule_types', ['name' => $rule->name, 'types' => implode(', ', FieldRule::TYPES)]),
                isset($seen[$rule->name]) => $add(self::ERROR, 'rule_duplicate', ['name' => $rule->name]),
                default => null,
            };

            $seen[$rule->name] = true;
        }
    }

    /** Anderes Plugin mit einer Formelfunktion dieses Namens (id), sonst null. */
    private function functionOwner(string $name, SaftPlugin $except): ?string
    {
        foreach ($this->plugins->all() as $id => $plugin) {
            if ($id !== $except->id() && in_array($name, array_map(static fn (FormulaFunction $f): string => $f->name, $plugin->formulaFunctions()), true)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Widget-Klassen des Plugins (Ordner Widgets neben der Plugin-Klasse, wie SaftPlugin::register()).
     *
     * @return list<class-string<Widget>>
     */
    public function widgets(SaftPlugin $plugin): array
    {
        $reflection = new ReflectionClass($plugin);
        $directory = dirname((string) $reflection->getFileName()).'/Widgets';

        if (! is_dir($directory)) {
            return [];
        }

        $widgets = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
            $class = $reflection->getNamespaceName().'\\Widgets\\'.str_replace('/', '\\', $relative);

            if ($file->getExtension() === 'php' && class_exists($class) && is_subclass_of($class, Widget::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $widgets[] = $class;
            }
        }

        sort($widgets);

        return $widgets;
    }

    /**
     * Felder des Table-Designers, die Prüfregeln oder Formelfunktionen verwenden, die es nicht (mehr) gibt – etwa weil
     * ihr Plugin entfernt wurde oder ruht. Prüfregeln werden dann übersprungen, Formeln ergeben leere Werte.
     *
     * @return list<string> Meldungen
     */
    public function tableDesignerIssues(): array
    {
        $issues = [];
        $functions = FormulaAnalyzer::functions();

        foreach (CustomField::query()->with('custom_table')->orderBy('custom_table_id')->orderBy('sort_order')->get() as $field) {
            $where = $field->custom_table?->name.'.'.$field->name;

            foreach (array_diff(FieldRuleCheck::selected($field), array_keys($this->plugins->fieldRules($field->type->value))) as $rule) {
                $issues[] = __('plugins.check.field_rule_unavailable', ['field' => $where, 'rule' => $rule]);
            }

            $formula = (string) ($field->optionsArray()['formula'] ?? '');

            if ($field->type !== FieldType::Computed || $formula === '') {
                continue;
            }

            try {
                $calls = self::calls(app(Parser::class)->parse($formula));
            } catch (Throwable) {
                continue;
            }

            foreach (array_unique(array_diff($calls, [...array_keys($functions), ...FormulaAnalyzer::AGGREGATES])) as $name) {
                $issues[] = __('plugins.check.field_function_unavailable', ['field' => $where, 'function' => $name]);
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $node
     * @return list<string> Namen aller Funktionsaufrufe im Formelbaum
     */
    private static function calls(array $node): array
    {
        $names = isset($node['call']) ? [(string) $node['call']] : [];

        foreach ($node as $child) {
            if (is_array($child)) {
                $names = [...$names, ...self::calls($child)];
            }
        }

        return $names;
    }

    /**
     * Klassen von SAFT, die der Code in $directory verwendet, aber nicht zur Plugin-API gehören.
     *
     * @return list<string> „Datei: Klasse“, Datei relativ zu $directory
     */
    public function apiViolations(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/(?:^use\s+|\\\\)(App\\\\[A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($file->getPathname()), $matches);

            foreach (array_unique($matches[1]) as $class) {
                $class = rtrim($class, '\\');

                if (! ApiManifest::covers($class)) {
                    $violations[] = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1).': '.$class;
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * Ordner unter plugins/, deren Paket nicht installiert ist (z. B. frisch erzeugt, noch ohne composer require).
     *
     * @return array<string, string> Ordner => Paketname
     */
    public function uninstalled(): array
    {
        $folders = [];

        foreach (glob(base_path('plugins/*/composer.json')) ?: [] as $manifest) {
            $name = json_decode((string) file_get_contents($manifest), true)['name'] ?? null;

            if (is_string($name) && ! InstalledVersions::isInstalled($name)) {
                $folders['plugins/'.basename(dirname($manifest))] = $name;
            }
        }

        return $folders;
    }

    private function permissionExists(string $name): bool
    {
        try {
            return Permission::query()->where('name', $name)->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
