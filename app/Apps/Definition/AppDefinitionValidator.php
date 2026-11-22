<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\DynamicData\DataPermissions;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\IdentifierValidator;
use Illuminate\Support\Str;

/**
 * Prüft eine App-Definition „saft-app/1“ auf Aufbau und innere Stimmigkeit: Pflichtangaben, stabile Schlüssel
 * (UUID, eindeutig), bekannte Feldtypen und Verweise innerhalb der Definition (Verknüpfungen, Auswertungen, Menüs,
 * Rollen). Meldungen mit Pfad, z. B. „tables[2].fields[5].type: unbekannter Typ „foo““. Ob sich die App in einer
 * Installation anlegen lässt (Namenskonflikte, Plugins), prüft erst das Installieren (Etappe 4b).
 */
final class AppDefinitionValidator
{
    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $keys = [];

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> Meldungen; leer = in Ordnung
     */
    public function errors(array $definition): array
    {
        $this->errors = [];
        $this->keys = [];

        if (($definition['format'] ?? null) !== AppDefinition::FORMAT) {
            $this->fail('format', __('apps.definition.errors.format', ['format' => AppDefinition::FORMAT]));

            return $this->errors;
        }

        $app = $definition['app'] ?? null;

        if (! is_array($app)) {
            $this->fail('app', __('apps.definition.errors.missing'));

            return $this->errors;
        }

        $this->key('app.key', $app['key'] ?? null);
        $default = (string) config('locales.default');

        if (! is_array($app['name'] ?? null) || blank($app['name'][$default] ?? null)) {
            $this->fail('app.name.'.$default, __('apps.definition.errors.missing'));
        }

        foreach (['tables', 'external_tables', 'report_links', 'reports', 'menus', 'roles', 'tasks', 'documents'] as $list) {
            if (! array_is_list((array) ($definition[$list] ?? []))) {
                $this->fail($list, __('apps.definition.errors.list'));
            }
        }

        $tables = $this->tables((array) ($definition['tables'] ?? []));
        $external = array_column((array) ($definition['external_tables'] ?? []), 'name');
        $known = [...$tables, ...array_map('strval', $external)];

        foreach ((array) ($definition['external_tables'] ?? []) as $i => $table) {
            $this->key("external_tables[{$i}].key", $table['key'] ?? null);

            if (blank(($table['source'] ?? [])['table'] ?? null)) {
                $this->fail("external_tables[{$i}].source.table", __('apps.definition.errors.missing'));
            }

            foreach ((array) ($table['fields'] ?? []) as $j => $field) {
                $this->key("external_tables[{$i}].fields[{$j}].key", $field['key'] ?? null);
            }
        }

        // Schnittstellen anderer Apps (Etappe 5b): Ziel von Verknüpfungen und Tabellenverknüpfungen, Leserechte von Rollen.
        $required = $this->requires($definition['requires'] ?? []);
        $reachable = [...$known, ...$required];

        $this->relations((array) ($definition['tables'] ?? []), $reachable);
        $reports = $this->reports((array) ($definition['reports'] ?? []), $known);
        $menus = $this->menus((array) ($definition['menus'] ?? []), $known, $reports);
        $this->links((array) ($definition['report_links'] ?? []), $known, $reachable);
        $this->roles((array) ($definition['roles'] ?? []), $reachable, $menus);

        // Dokumentvorlagen (B-124): Grundtabelle aus der Definition, Sprach- und Ablagefeld ihre Felder.
        $fieldsOf = collect((array) ($definition['tables'] ?? []))->mapWithKeys(static fn (mixed $t): array => is_array($t) ? [(string) ($t['name'] ?? '') => array_column((array) ($t['fields'] ?? []), 'name')] : [])->all();

        foreach ((array) ($definition['documents'] ?? []) as $i => $document) {
            $this->key("documents[{$i}].key", $document['key'] ?? null);

            foreach (['name', 'body'] as $attr) {
                if (blank($document[$attr] ?? null)) {
                    $this->fail("documents[{$i}].{$attr}", __('apps.definition.errors.missing'));
                }
            }

            $table = (string) ($document['table'] ?? '');

            if (! array_key_exists($table, $fieldsOf)) {
                $this->fail("documents[{$i}].table", __('apps.definition.errors.unknown_table', ['table' => $table]));

                continue;
            }

            foreach (['language_field', 'file_field'] as $attr) {
                if (filled($document[$attr] ?? null) && ! in_array($document[$attr], $fieldsOf[$table], true)) {
                    $this->fail("documents[{$i}].{$attr}", __('apps.definition.errors.unknown_field', ['field' => $table.'.'.$document[$attr]]));
                }
            }
        }

        foreach ((array) ($definition['tasks'] ?? []) as $i => $task) {
            $this->key("tasks[{$i}].key", $task['key'] ?? null);

            if (blank($task['type'] ?? null)) {
                $this->fail("tasks[{$i}].type", __('apps.definition.errors.missing'));
            }
        }

        return $this->errors;
    }

    /**
     * @param  array<int, mixed>  $tables
     * @return list<string> technische Namen
     */
    private function tables(array $tables): array
    {
        $names = [];
        $types = array_map(static fn (FieldType $t): string => $t->value, FieldType::cases());

        foreach ($tables as $i => $table) {
            $path = "tables[{$i}]";
            $this->key("{$path}.key", $table['key'] ?? null);
            $name = (string) ($table['name'] ?? '');

            if (preg_match(IdentifierValidator::PATTERN, $name) !== 1) {
                $this->fail("{$path}.name", __('apps.definition.errors.name', ['name' => $name]));
            } elseif (in_array($name, $names, true)) {
                $this->fail("{$path}.name", __('apps.definition.errors.duplicate', ['value' => $name]));
            }

            $names[] = $name;
            $fields = [];

            foreach ((array) ($table['fields'] ?? []) as $j => $field) {
                $this->key("{$path}.fields[{$j}].key", $field['key'] ?? null);
                $fieldName = (string) ($field['name'] ?? '');

                if (preg_match(IdentifierValidator::PATTERN, $fieldName) !== 1 || in_array($fieldName, $fields, true)) {
                    $this->fail("{$path}.fields[{$j}].name", __('apps.definition.errors.name', ['name' => $fieldName]));
                }

                $fields[] = $fieldName;

                if (! in_array($field['type'] ?? null, $types, true)) {
                    $this->fail("{$path}.fields[{$j}].type", __('apps.definition.errors.type', ['type' => (string) ($field['type'] ?? '')]));
                }
            }
        }

        return $names;
    }

    /**
     * Verknüpfungsfelder zeigen auf Tabellen der Definition (Verknüpfungen nur innerhalb einer App).
     *
     * @param  array<int, mixed>  $tables
     * @param  list<string>  $known
     */
    private function relations(array $tables, array $known): void
    {
        foreach ($tables as $i => $table) {
            foreach ((array) ($table['fields'] ?? []) as $j => $field) {
                $target = (string) (($field['options'] ?? [])['table'] ?? '');

                if (($field['type'] ?? null) === FieldType::Relation->value && ! in_array($target, $known, true)) {
                    $this->fail("tables[{$i}].fields[{$j}].options.table", __('apps.definition.errors.unknown_table', ['table' => $target]));
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $reports
     * @param  list<string>  $known
     * @return list<string> Namen der Auswertungen
     */
    private function reports(array $reports, array $known): array
    {
        $names = [];

        foreach ($reports as $i => $report) {
            $this->key("reports[{$i}].key", $report['key'] ?? null);
            $base = (string) (($report['definition'] ?? [])['base'] ?? '');

            if (! in_array($base, $known, true)) {
                $this->fail("reports[{$i}].definition.base", __('apps.definition.errors.unknown_table', ['table' => $base]));
            }

            $names[] = (string) ($report['name'] ?? '');
        }

        return $names;
    }

    /**
     * @param  array<int, mixed>  $menus
     * @param  list<string>  $known
     * @param  list<string>  $reports
     * @return list<string> Menüpfade
     */
    private function menus(array $menus, array $known, array $reports): array
    {
        $paths = [];

        foreach ($menus as $i => $menu) {
            $this->key("menus[{$i}].key", $menu['key'] ?? null);
            // Die übergeordnete Gruppe darf ausserhalb der App liegen (z. B. eine Systemgruppe); das Installieren legt
            // fehlende Gruppen an.
            $target = (array) ($menu['target'] ?? []);

            if (($target['type'] ?? null) === 'data' && ! in_array((string) ($target['table'] ?? ''), $known, true)) {
                $this->fail("menus[{$i}].target.table", __('apps.definition.errors.unknown_table', ['table' => (string) ($target['table'] ?? '')]));
            }

            if (($target['type'] ?? null) === 'report' && ! in_array((string) ($target['report'] ?? ''), $reports, true)) {
                $this->fail("menus[{$i}].target.report", __('apps.definition.errors.unknown_report', ['report' => (string) ($target['report'] ?? '')]));
            }

            $paths[] = (string) ($menu['path'] ?? '');
        }

        return $paths;
    }

    /**
     * @param  array<int, mixed>  $links
     * @param  list<string>  $known  Tabellen der App (Quelle)
     * @param  list<string>  $reachable  dazu Schnittstellen anderer Apps (Ziel)
     */
    private function links(array $links, array $known, array $reachable): void
    {
        foreach ($links as $i => $link) {
            $this->key("report_links[{$i}].key", $link['key'] ?? null);

            foreach (['source' => [Str::before((string) ($link['source'] ?? ''), '.'), $known], 'target_table' => [(string) ($link['target_table'] ?? ''), $reachable]] as $part => [$table, $allowed]) {
                if (! in_array($table, $allowed, true)) {
                    $this->fail("report_links[{$i}].{$part}", __('apps.definition.errors.unknown_table', ['table' => $table]));
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $roles
     * @param  list<string>  $known
     * @param  list<string>  $menus
     */
    private function roles(array $roles, array $known, array $menus): void
    {
        foreach ($roles as $i => $role) {
            $this->key("roles[{$i}].key", $role['key'] ?? null);

            if (blank($role['name'] ?? null)) {
                $this->fail("roles[{$i}].name", __('apps.definition.errors.missing'));
            }

            foreach ((array) ($role['permissions'] ?? []) as $j => $permission) {
                if (str_starts_with((string) $permission, DataPermissions::PREFIX)
                    && ! in_array(Str::beforeLast(Str::after((string) $permission, DataPermissions::PREFIX), '.'), $known, true)) {
                    $this->fail("roles[{$i}].permissions[{$j}]", __('apps.definition.errors.unknown_table', ['table' => (string) $permission]));
                }
            }

            foreach ((array) ($role['hidden_menus'] ?? []) as $j => $path) {
                if (! in_array((string) $path, $menus, true)) {
                    $this->fail("roles[{$i}].hidden_menus[{$j}]", __('apps.definition.errors.unknown_menu', ['menu' => (string) $path]));
                }
            }
        }
    }

    /**
     * Benötigte Schnittstellen anderer Apps: Liste von Apps mit Schlüssel und Tabellen mit Namen.
     *
     * @return list<string> Namen der benötigten Tabellen
     */
    private function requires(mixed $requires): array
    {
        if (! is_array($requires) || ! array_is_list($requires)) {
            $this->fail('requires', __('apps.definition.errors.list'));

            return [];
        }

        $names = [];

        foreach ($requires as $i => $entry) {
            foreach ((array) (is_array($entry) ? ($entry['tables'] ?? []) : []) as $j => $table) {
                $name = is_array($table) ? (string) ($table['name'] ?? '') : '';

                if ($name === '') {
                    $this->fail("requires[{$i}].tables[{$j}].name", __('apps.definition.errors.missing'));
                }

                $names[] = $name;
            }
        }

        return $names;
    }

    /** Stabiler Schlüssel: UUID, in der ganzen Definition eindeutig. */
    private function key(string $path, mixed $key): void
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            $this->fail($path, __('apps.definition.errors.key'));

            return;
        }

        if (isset($this->keys[$key])) {
            $this->fail($path, __('apps.definition.errors.duplicate', ['value' => $key]));
        }

        $this->keys[$key] = true;
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[] = $path.': '.$message;
    }
}
