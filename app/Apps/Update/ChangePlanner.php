<?php

declare(strict_types=1);

namespace App\Apps\Update;

use App\Apps\AppInterfaces;
use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppDefinitionValidator;
use App\Apps\Definition\AppInstaller;
use App\Apps\Definition\References;
use App\DynamicData\Schema\FieldType;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Services\DynamicSchemaService;
use Throwable;

/**
 * Änderungsplan (B-107, Etappe 6): vergleicht über die stabilen Schlüssel drei Stände – Ausgangsstand (zuletzt
 * installiert bzw. aktualisiert), aktuellen Stand (aus der Datenbank) und Paket. Was nur das Paket ändert, wird
 * übernommen; was nur lokal geändert wurde, bleibt; was beide verschieden ändern, ist ein Konflikt (Vorauswahl
 * „behalten“). Ohne Ausgangsstand ist jede Abweichung ein Konflikt; Neues aus dem Paket wird trotzdem angelegt.
 *
 * Einstufung: sicher, heikel (Daten betroffen, z. B. Typ, Pflicht, technischer Name, Formel), zerstörend (Feld oder
 * Tabelle mit Daten löschen). Mit Daten wird beim Entfernen zuerst nur deaktiviert; Löschen ist ein eigener Schritt.
 */
final class ChangePlanner
{
    private const array APP_ATTRS = ['name', 'description', 'icon'];

    private const array TABLE_ATTRS = ['label', 'description', 'icon', 'is_active', 'export_enabled', 'import_enabled', 'rules', 'details', 'is_shared', 'shared_fields'];

    private const array FIELD_ATTRS = ['name', 'label', 'type', 'options', 'validation_rules', 'is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form', 'is_active', 'sort_order'];

    /** Teile, die erst Etappe 6b ausführt: Liste in der Definition => Objektart */
    private const array LATER = ['external_tables' => 'external_table', 'report_links' => 'report_link', 'reports' => 'report', 'menus' => 'menu', 'roles' => 'role', 'tasks' => 'task'];

    public function __construct(
        private readonly AppDefinition $definitions,
        private readonly AppDefinitionValidator $validator,
        private readonly AppInstaller $installer,
        private readonly AppInterfaces $interfaces,
        private readonly PluginRegistry $plugins,
        private readonly DynamicSchemaService $service,
    ) {}

    /** @var list<string> Tabellen und Felder, die es nur in SAFT gibt (seit dem Ausgangsstand angelegt; bleiben) */
    private array $local = [];

    /** @param  array<string, mixed>  $target */
    public function plan(App $app, array $target, ?User $user = null): ChangePlan
    {
        $this->local = [];
        $blockers = array_slice($this->validator->errors($target), 0, 20);

        if ($blockers !== []) {
            return new ChangePlan([], $blockers, [], $target);
        }

        if ((string) ($target['app']['key'] ?? '') !== (string) $app->uuid) {
            return new ChangePlan([], [__('apps.update.errors.other_app', ['app' => References::defaultLabel($target['app']['name'] ?? []), 'current' => (string) $app->name])], [], $target);
        }

        $current = $this->definitions->build($app, AppDefinition::PARTS, $user);
        $baseline = AppBaseline::query()->where('app_id', $app->id)->latest('id')->first();
        $base = $baseline === null ? null : (array) $baseline->definition;
        [$requires, $missing, $notes] = $this->installer->requirements((array) ($target['requires'] ?? []));
        $warnings = $notes;

        if ($base === null) {
            $warnings[] = __('apps.update.warnings.no_baseline');
        }

        $from = (string) ($app->version ?? '');
        $to = (string) ($target['app']['version'] ?? '');

        if ($to === '') {
            $warnings[] = __('apps.update.warnings.version_missing');
        } elseif ($from !== '' && version_compare($to, $from, '<=')) {
            $warnings[] = __('apps.update.warnings.version', ['from' => $from, 'to' => $to]);
        }

        foreach (array_keys((array) ($target['plugins'] ?? [])) as $plugin) {
            if (! $this->plugins->has((string) $plugin)) {
                $warnings[] = __('apps.install.warnings.plugin', ['plugin' => (string) $plugin]);
            }
        }

        $steps = [
            ...$this->appSteps($base['app'] ?? null, $current['app'], $target['app'], $base !== null),
            ...$this->tableSteps($base === null ? null : (array) ($base['tables'] ?? []), $current['tables'], (array) $target['tables']),
            ...$this->laterSteps($base, $current, $target),
        ];

        return new ChangePlan($steps, $missing, $warnings, $target, $from !== '' ? $from : null, $baseline?->version, $base !== null, $requires, $this->local);
    }

    // --- App ---------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $base
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     * @return list<ChangeStep>
     */
    private function appSteps(?array $base, array $current, array $target, bool $hasBase): array
    {
        [$package, $conflicts] = $this->attrDiff($hasBase ? $base : null, $current, $target, self::APP_ATTRS, static fn (array $o, string $a): mixed => $o[$a] ?? null);
        $label = References::defaultLabel($target['name'] ?? []);

        return $this->split('app', ChangeStep::UPDATE, (string) $target['key'], $label, $package, $conflicts, $target);
    }

    // --- Tabellen und Felder -----------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>|null  $base
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $target
     * @return list<ChangeStep>
     */
    private function tableSteps(?array $base, array $current, array $target): array
    {
        $bas = $base === null ? null : self::byKey($base);
        $cur = self::byKey($current);
        $tgt = self::byKey($target);
        $steps = [];

        foreach ($tgt as $key => $table) {
            $label = self::label($table);

            if (! isset($cur[$key])) {
                // Lokal entfernt, im Paket noch da: nur bei Änderungen im Paket als Konflikt anbieten.
                if ($bas !== null && isset($bas[$key])) {
                    if (! self::same($bas[$key], $table)) {
                        $steps[] = new ChangeStep('table:'.$key, 'table', ChangeStep::CREATE, $key, $label, conflict: true, notes: [__('apps.update.notes.removed_locally')], target: $table);
                    }

                    continue;
                }

                $steps[] = new ChangeStep('table:'.$key, 'table', ChangeStep::CREATE, $key, $label, notes: [trans_choice('apps.update.notes.fields', count((array) $table['fields']), ['count' => count((array) $table['fields'])])], target: $table);

                continue;
            }

            $model = CustomTable::query()->where('name', (string) $cur[$key]['name'])->with('fields')->first();
            [$package, $conflicts] = $this->attrDiff($bas[$key] ?? null, $cur[$key], $table, self::TABLE_ATTRS, self::tableValue(...));

            foreach ([[$package, false], [$conflicts, true]] as [$changes, $conflict]) {
                if ($changes !== []) {
                    [$level, $notes, $blocked] = $this->tableRisk($changes, $model);
                    $steps[] = new ChangeStep('table:'.$key.($conflict ? ':conflict' : ''), 'table', ChangeStep::UPDATE, $key, $label, $changes, $level, $conflict, $notes, $table, blocked: $blocked);
                }
            }

            $steps = [...$steps, ...$this->fieldSteps($bas === null ? null : self::byKey((array) ($bas[$key]['fields'] ?? [])), self::byKey((array) $cur[$key]['fields']), self::byKey((array) $table['fields']), $key, $label, $model, $bas !== null && isset($bas[$key]))];
        }

        foreach ($cur as $key => $table) {
            if (isset($tgt[$key])) {
                continue;
            }

            if ($bas !== null && ! isset($bas[$key])) {
                // Seit dem Ausgangsstand in SAFT angelegt: bleibt (nur als Hinweis).
                $this->local[] = __('apps.update.kind.table').' · '.self::label($table).' ('.trans_choice('apps.update.notes.rows', $rows = $this->count((string) $table['name']), ['count' => $rows]).')';

                continue;
            }

            $model = CustomTable::query()->where('name', (string) $table['name'])->with('fields')->first();
            $rows = $model === null ? 0 : $this->count($model->name);
            $conflict = $bas === null || ! self::same($bas[$key], $table);
            $blocked = $model !== null && $this->interfaces->usages($model) !== [] ? __('apps.update.notes.interface_used', ['usages' => implode(', ', $this->interfaces->usages($model))]) : null;
            $steps = [...$steps, ...$this->removal('table', $key, self::label($table), $rows, (bool) ($table['is_active'] ?? true), $conflict, $blocked, null)];
        }

        return $steps;
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $bas
     * @param  array<string, array<string, mixed>>  $cur
     * @param  array<string, array<string, mixed>>  $tgt
     * @return list<ChangeStep>
     */
    private function fieldSteps(?array $bas, array $cur, array $tgt, string $tableKey, string $tableLabel, ?CustomTable $model, bool $tableInBase): array
    {
        $steps = [];
        $rows = $model === null ? 0 : $this->count($model->name);

        foreach ($tgt as $key => $field) {
            $label = $tableLabel.' › '.$field['name'];

            if (! isset($cur[$key])) {
                if ($bas !== null && isset($bas[$key])) {
                    if (! self::same($bas[$key], $field)) {
                        $steps[] = new ChangeStep('field:'.$key, 'field', ChangeStep::CREATE, $key, $label, conflict: true, notes: [__('apps.update.notes.removed_locally')], target: $field, table: $tableKey);
                    }

                    continue;
                }

                $required = ! empty($field['is_required']) && $rows > 0;
                $steps[] = new ChangeStep('field:'.$key, 'field', ChangeStep::CREATE, $key, $label, level: $required ? ChangeStep::RISKY : ChangeStep::SAFE,
                    notes: $required ? [__('apps.update.notes.required_new', ['count' => $rows])] : [], target: $field, table: $tableKey);

                continue;
            }

            [$package, $conflicts] = $this->attrDiff($bas[$key] ?? null, $cur[$key], $field, self::FIELD_ATTRS, self::fieldValue(...));

            foreach ([[$package, false], [$conflicts, true]] as [$changes, $conflict]) {
                if ($changes !== []) {
                    [$level, $notes, $blocked] = $this->fieldRisk($changes, $cur[$key], $model);
                    $steps[] = new ChangeStep('field:'.$key.($conflict ? ':conflict' : ''), 'field', ChangeStep::UPDATE, $key, $label, $changes, $level, $conflict, $notes, $field, $tableKey, $blocked);
                }
            }
        }

        foreach ($cur as $key => $field) {
            if (isset($tgt[$key])) {
                continue;
            }

            if ($tableInBase && $bas !== null && ! isset($bas[$key])) {
                $values = $model === null ? 0 : $this->count($model->name, (string) $field['name']);
                $this->local[] = __('apps.update.kind.field').' · '.$tableLabel.' › '.$field['name'].' ('.trans_choice('apps.update.notes.values', $values, ['count' => $values]).')';

                continue;
            }

            $values = $model === null ? 0 : $this->count($model->name, (string) $field['name']);
            $conflict = $bas === null || ! isset($bas[$key]) || ! self::same($bas[$key], $field);
            $usages = $model === null ? [] : $this->interfaces->usages($model, (string) $field['name']);
            $blocked = $usages !== [] ? __('apps.update.notes.interface_used', ['usages' => implode(', ', $usages)]) : null;
            $steps = [...$steps, ...$this->removal('field', $key, $tableLabel.' › '.$field['name'], $values, (bool) ($field['is_active'] ?? true), $conflict, $blocked, $tableKey)];
        }

        return $steps;
    }

    /**
     * Entfernen: mit Daten zuerst deaktivieren (heikel, vorgewählt) und Löschen als eigener, zerstörender Schritt; ohne
     * Daten nur Löschen (heikel).
     *
     * @return list<ChangeStep>
     */
    private function removal(string $kind, string $key, string $label, int $count, bool $active, bool $conflict, ?string $blocked, ?string $table): array
    {
        $note = $kind === 'table'
            ? trans_choice('apps.update.notes.rows', $count, ['count' => $count])
            : trans_choice('apps.update.notes.values', $count, ['count' => $count]);
        $steps = [];

        if ($count > 0 && $active) {
            $steps[] = new ChangeStep("{$kind}:{$key}:deactivate", $kind, ChangeStep::DEACTIVATE, $key, $label, level: ChangeStep::RISKY, conflict: $conflict, notes: [$note, __('apps.update.notes.data_kept')], table: $table, blocked: $blocked);
        }

        $steps[] = new ChangeStep("{$kind}:{$key}:delete", $kind, ChangeStep::DELETE, $key, $label, level: $count > 0 ? ChangeStep::DESTRUCTIVE : ChangeStep::RISKY, conflict: $conflict, notes: [$note], table: $table, blocked: $blocked);

        return $steps;
    }

    // --- Einstufung --------------------------------------------------------------------------------------------

    /**
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes
     * @return array{0: string, 1: list<string>, 2: string|null}
     */
    private function tableRisk(array $changes, ?CustomTable $model): array
    {
        $level = ChangeStep::SAFE;
        $notes = [];
        $blocked = null;

        foreach ($changes as ['attr' => $attr, 'from' => $from, 'to' => $to]) {
            if ($attr === 'rules') {
                $level = ChangeStep::RISKY;
                $notes[] = __('apps.update.notes.rules');
            } elseif ($attr === 'is_active' && $to === false) {
                $level = ChangeStep::RISKY;
                $notes[] = __('apps.update.notes.deactivate');
            } elseif (in_array($attr, ['is_shared', 'shared_fields'], true)) {
                $removed = $attr === 'is_shared' ? ($to === false ? ['*'] : []) : array_values(array_diff((array) $from, (array) $to));

                if ($removed !== [] && $model !== null) {
                    $used = $removed === ['*'] ? $this->interfaces->usages($model) : array_merge(...array_map(fn (string $f): array => $this->interfaces->usages($model, $f), $removed));
                    $blocked = $used !== [] ? __('apps.update.notes.interface_used', ['usages' => implode(', ', array_unique($used))]) : $blocked;
                    $level = ChangeStep::RISKY;
                    $notes[] = __('apps.update.notes.interface_version');
                }
            }
        }

        if ($blocked === null && $model !== null && collect($changes)->contains(static fn (array $c): bool => $c['attr'] === 'is_active' && $c['to'] === false) && $this->interfaces->usages($model) !== []) {
            $blocked = __('apps.update.notes.interface_used', ['usages' => implode(', ', $this->interfaces->usages($model))]);
        }

        return [$level, array_values(array_unique($notes)), $blocked];
    }

    /**
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes
     * @param  array<string, mixed>  $current
     * @return array{0: string, 1: list<string>, 2: string|null}
     */
    private function fieldRisk(array $changes, array $current, ?CustomTable $model): array
    {
        $level = ChangeStep::SAFE;
        $notes = [];
        $column = (string) $current['name'];
        $structural = false;

        foreach ($changes as ['attr' => $attr, 'from' => $from, 'to' => $to]) {
            $risky = match ($attr) {
                'name' => __('apps.update.notes.rename', ['from' => (string) $from, 'to' => (string) $to]),
                'type' => trans_choice('apps.update.notes.type', $values = $this->count((string) $model?->name, $column), ['count' => $values]),
                'is_required' => $to === true && ($empty = $this->count((string) $model?->name) - $this->count((string) $model?->name, $column)) > 0
                    ? trans_choice('apps.update.notes.required', $empty, ['count' => $empty]) : null,
                'is_unique' => $to === true && $this->duplicates((string) $model?->name, $column) ? __('apps.update.notes.duplicates') : null,
                'is_active' => $to === false ? __('apps.update.notes.deactivate') : null,
                'validation_rules' => ($broken = $this->ruleViolations((string) $model?->name, $column, (array) $to)) > 0
                    ? trans_choice('apps.update.notes.field_rules', $broken, ['count' => $broken]) : null,
                'options' => $this->optionsRisk((array) $from, (array) $to, (string) ($current['type'] ?? '')),
                default => null,
            };

            if ($risky !== null) {
                $level = ChangeStep::RISKY;
                $notes[] = $risky;
            }

            $structural = $structural || in_array($attr, ['name', 'type'], true) || ($attr === 'is_active' && $to === false);
        }

        $usages = $structural && $model !== null ? $this->interfaces->usages($model, $column) : [];

        if ($usages !== []) {
            return [$level, $notes, __('apps.update.notes.interface_used', ['usages' => implode(', ', $usages)])];
        }

        // Typ bzw. Optionen: geht das mit den vorhandenen Daten (wie im Table-Designer)?
        $field = $model?->fields->firstWhere('name', $column);
        $data = array_column(array_values(array_filter($changes, static fn (array $c): bool => in_array($c['attr'], ['type', 'options'], true))), 'to', 'attr');

        return [$level, $notes, $field !== null && $data !== [] ? $this->service->fieldChangeProblem($field, $data) : null];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function optionsRisk(array $from, array $to, string $type): ?string
    {
        if ($type === FieldType::Relation->value && ($from['table'] ?? null) !== ($to['table'] ?? null)) {
            return __('apps.update.notes.relation_target', ['table' => (string) ($to['table'] ?? '–')]);
        }

        if ($type === FieldType::Computed->value && ($from['formula'] ?? null) !== ($to['formula'] ?? null)) {
            return __('apps.update.notes.formula');
        }

        $values = static fn (array $o): array => array_map(static fn (mixed $c): string => (string) (is_array($c) ? ($c['value'] ?? '') : $c), (array) ($o['options'] ?? []));
        $removed = array_diff($values($from), $values($to));

        return $type === FieldType::Select->value && $removed !== [] ? __('apps.update.notes.choices_removed', ['values' => implode(', ', $removed)]) : null;
    }

    /** Anzahl Datensätze bzw. Werte (nicht leer) einer Spalte; 0, wenn die Tabelle fehlt. */
    private function count(string $table, ?string $column = null): int
    {
        if ($table === '') {
            return 0;
        }

        try {
            $query = DynamicRecord::forTableName($table)->newQuery();

            return $column === null ? $query->count() : $query->whereNotNull($column)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Wie viele vorhandene Werte verletzen die (neuen) Feldregeln – min/max, Länge, E-Mail, URL? Geprüft in PHP (keine
     * Datenbankfunktionen), seitenweise.
     *
     * @param  array<string, mixed>  $rules
     */
    private function ruleViolations(string $table, string $column, array $rules): int
    {
        $rules = array_intersect_key($rules, array_flip(['min', 'max', 'min_length', 'max_length', 'email', 'url']));

        if ($table === '' || $rules === []) {
            return 0;
        }

        $broken = 0;

        try {
            foreach (DynamicRecord::forTableName($table)->newQuery()->whereNotNull($column)->orderBy('id')->select(['id', $column])->lazy(1000) as $record) {
                $value = $record->getAttribute($column);
                $text = (string) $value;
                $length = mb_strlen($text);
                $broken += (int) (
                    (isset($rules['min']) && is_numeric($value) && $value < $rules['min'])
                    || (isset($rules['max']) && is_numeric($value) && $value > $rules['max'])
                    || (isset($rules['min_length']) && $length < (int) $rules['min_length'])
                    || (isset($rules['max_length']) && $length > (int) $rules['max_length'])
                    || (! empty($rules['email']) && $text !== '' && filter_var($text, FILTER_VALIDATE_EMAIL) === false)
                    || (! empty($rules['url']) && $text !== '' && filter_var($text, FILTER_VALIDATE_URL) === false)
                );
            }
        } catch (Throwable) {
            return 0;
        }

        return $broken;
    }

    private function duplicates(string $table, string $column): bool
    {
        try {
            $query = DynamicRecord::forTableName($table)->newQuery()->whereNotNull($column);

            return $query->clone()->count() > $query->clone()->distinct()->count($column);
        } catch (Throwable) {
            return false;
        }
    }

    // --- Weitere Teile (Ausführen in Etappe 6b) ------------------------------------------------------------------

    /**
     * Auf Objektebene: neu, geändert, entfernt – mit Konflikten. Teile, die das Paket nicht enthält (ältere Dateien ohne
     * „parts“: leere Liste), bleiben unberührt.
     *
     * @param  array<string, mixed>|null  $base
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     * @return list<ChangeStep>
     */
    private function laterSteps(?array $base, array $current, array $target): array
    {
        $parts = (array) ($target['parts'] ?? []);
        $steps = [];

        foreach (self::LATER as $list => $kind) {
            $optional = in_array($list, ['reports', 'roles', 'tasks'], true);

            if ($optional && ! in_array($list, $parts, true) && (array) ($target[$list] ?? []) === []) {
                continue;
            }

            $bas = $base === null ? null : self::byKey((array) ($base[$list] ?? []));
            $cur = self::byKey((array) ($current[$list] ?? []));
            $tgt = self::byKey((array) ($target[$list] ?? []));

            foreach ($tgt as $key => $object) {
                $label = self::objectLabel($kind, $object);

                if (! isset($cur[$key])) {
                    if ($bas === null || ! isset($bas[$key]) || ! self::same($bas[$key], $object)) {
                        $steps[] = new ChangeStep("{$kind}:{$key}", $kind, ChangeStep::CREATE, $key, $label, conflict: $bas !== null && isset($bas[$key]), target: $object);
                    }
                } elseif (! self::same($cur[$key], $object)) {
                    $local = $bas !== null && isset($bas[$key]) && ! self::same($bas[$key], $cur[$key]);
                    $packaged = $bas === null || ! isset($bas[$key]) || ! self::same($bas[$key], $object);

                    if ($packaged) {
                        $steps[] = new ChangeStep("{$kind}:{$key}", $kind, ChangeStep::UPDATE, $key, $label, conflict: $local || $bas === null, target: $object);
                    }
                }
            }

            foreach ($cur as $key => $object) {
                if (! isset($tgt[$key]) && ($bas === null || isset($bas[$key]))) {
                    $steps[] = new ChangeStep("{$kind}:{$key}:delete", $kind, ChangeStep::DELETE, $key, self::objectLabel($kind, $object), level: ChangeStep::RISKY, conflict: $bas === null || ! self::same($bas[$key], $object));
                }
            }
        }

        $manual = static fn (?array $d): array => (array) (($d['app'] ?? [])['manual'] ?? []);

        if (in_array('manual', $parts, true) || $manual($target) !== []) {
            foreach ($manual($target) as $locale => $content) {
                $now = $manual($current)[$locale] ?? null;

                if ($now !== $content && ($base === null || ($manual($base)[$locale] ?? null) !== $content)) {
                    $steps[] = new ChangeStep('manual:'.$locale, 'manual', $now === null ? ChangeStep::CREATE : ChangeStep::UPDATE, (string) $locale, __('apps.update.manual', ['locale' => strtoupper((string) $locale)]),
                        conflict: $now !== null && ($base === null || ($manual($base)[$locale] ?? null) !== $now));
                }
            }
        }

        return $steps;
    }

    // --- Vergleich ---------------------------------------------------------------------------------------------

    /**
     * Drei-Wege-Vergleich je Eigenschaft: nur im Paket geändert → übernehmen; nur lokal → behalten; beide verschieden →
     * Konflikt. Ohne Ausgangsobjekt ist jede Abweichung ein Konflikt.
     *
     * @param  array<string, mixed>|null  $base
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     * @param  list<string>  $attrs
     * @param  callable(array<string, mixed>, string): mixed  $value
     * @return array{0: list<array{attr: string, from: mixed, to: mixed}>, 1: list<array{attr: string, from: mixed, to: mixed}>}
     */
    private function attrDiff(?array $base, array $current, array $target, array $attrs, callable $value): array
    {
        $package = [];
        $conflicts = [];

        foreach ($attrs as $attr) {
            [$c, $t] = [$value($current, $attr), $value($target, $attr)];

            if (self::same($c, $t)) {
                continue;
            }

            $change = ['attr' => $attr, 'from' => $c, 'to' => $t];

            if ($base === null) {
                $conflicts[] = $change;
            } elseif (self::same($value($base, $attr), $c)) {
                $package[] = $change;
            } elseif (! self::same($value($base, $attr), $t)) {
                $conflicts[] = $change;
            }
        }

        return [$package, $conflicts];
    }

    /**
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $package
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $conflicts
     * @param  array<string, mixed>  $target
     * @return list<ChangeStep>
     */
    private function split(string $kind, string $action, string $key, string $label, array $package, array $conflicts, array $target): array
    {
        $steps = [];

        if ($package !== []) {
            $steps[] = new ChangeStep("{$kind}:{$key}", $kind, $action, $key, $label, $package, target: $target);
        }

        if ($conflicts !== []) {
            $steps[] = new ChangeStep("{$kind}:{$key}:conflict", $kind, $action, $key, $label, $conflicts, conflict: true, target: $target);
        }

        return $steps;
    }

    /** @param  array<string, mixed>  $table */
    private static function tableValue(array $table, string $attr): mixed
    {
        return match ($attr) {
            'is_shared' => (bool) ($table['is_shared'] ?? false),
            'is_active' => (bool) ($table['is_active'] ?? true),
            'export_enabled', 'import_enabled' => (bool) ($table[$attr] ?? false),
            'shared_fields' => collect((array) ($table['fields'] ?? []))->filter(static fn (array $f): bool => ! empty($f['is_shared']))->pluck('name')->sort()->values()->all(),
            default => $table[$attr] ?? null,
        };
    }

    /** @param  array<string, mixed>  $field */
    private static function fieldValue(array $field, string $attr): mixed
    {
        return match ($attr) {
            // Der Syntaxbaum einer Formel entsteht aus dem Text.
            'options' => array_diff_key((array) ($field['options'] ?? []), ['ast' => true]),
            'is_active', 'show_in_table', 'show_in_form' => (bool) ($field[$attr] ?? true),
            'is_required', 'is_unique', 'is_searchable', 'is_sortable' => (bool) ($field[$attr] ?? false),
            'sort_order' => (int) ($field[$attr] ?? 0),
            default => $field[$attr] ?? null,
        };
    }

    /** Gleich bis auf Reihenfolge der Schlüssel und leere Werte (null, '', []). */
    public static function same(mixed $a, mixed $b): bool
    {
        return json_encode(self::canon($a)) === json_encode(self::canon($b));
    }

    private static function canon(mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
            $value = array_filter(array_map(self::canon(...), $value), static fn (mixed $v): bool => $v !== null);

            return $value === [] ? null : $value;
        }

        return array_map(self::canon(...), $value);
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $objects): array
    {
        $keyed = [];

        foreach ($objects as $object) {
            if (is_array($object) && filled($object['key'] ?? null)) {
                $keyed[(string) $object['key']] = $object;
            }
        }

        return $keyed;
    }

    /** @param  array<string, mixed>  $table */
    private static function label(array $table): string
    {
        return References::defaultLabel($table['label'] ?? []) ?: (string) ($table['name'] ?? '');
    }

    /** @param  array<string, mixed>  $object */
    private static function objectLabel(string $kind, array $object): string
    {
        return match ($kind) {
            'menu' => (string) ($object['path'] ?? ''),
            'report_link' => References::defaultLabel($object['label'] ?? []) ?: (string) ($object['source'] ?? ''),
            'external_table' => self::label($object),
            default => (string) ($object['name'] ?? ''),
        };
    }
}
