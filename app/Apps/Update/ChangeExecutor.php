<?php

declare(strict_types=1);

namespace App\Apps\Update;

use App\Apps\Definition\AppInstaller;
use App\Apps\Definition\DefinitionObjects;
use App\Apps\Definition\References;
use App\Database\DatabaseBackup;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\AppManual;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaImporter;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Führt die gewählten Schritte eines Änderungsplans aus (B-107, Etappe 6a: App, Tabellen und Felder). Vorher eine
 * Sicherung; jeder Schritt über die Operationen des Table-Designers mit ihren Schutzregeln. Reihenfolge: App, neue
 * Tabellen, neue Felder (einfach, Verknüpfungen, Übernahmen, berechnet), Änderungen an Feldern und Tabellen, Freigaben,
 * Deaktivieren, zuletzt Löschen. Scheitert ein Schritt, bricht das Aktualisieren ab und meldet, was schon angewendet
 * ist (Schemaänderungen lassen sich nicht überall zurückrollen); ein neuer Plan zeigt dann nur noch den Rest. Nach
 * Erfolg ist das Paket der neue Ausgangsstand.
 */
final class ChangeExecutor
{
    /** @var list<string> */
    private array $done = [];

    /** @var array<string, mixed> Definition des Pakets (Elternmenüs über ihren Schlüssel) */
    private array $target = [];

    public function __construct(
        private readonly DynamicSchemaService $service,
        private readonly SchemaImporter $importer,
        private readonly References $refs,
        private readonly DatabaseBackup $backup,
        private readonly DefinitionObjects $objects,
        private readonly AppInstaller $installer,
    ) {}

    /**
     * @param  list<string>  $selected  IDs der gewählten Schritte
     * @return array{steps: list<string>, backup: string}
     *
     * @throws BusinessRuleException
     */
    public function apply(App $app, ChangePlan $plan, array $selected, bool $confirmDestructive, User $user): array
    {
        if (! $plan->canApply()) {
            throw new BusinessRuleException(implode(' ', $plan->blockers));
        }

        $steps = array_values(array_filter($plan->steps, static fn (ChangeStep $s): bool => $s->selectable() && in_array($s->id, $selected, true)));

        // Nichts gewählt: weder sichern noch einen neuen Ausgangsstand speichern.
        if ($steps === []) {
            throw new BusinessRuleException(__('apps.update.errors.nothing'));
        }

        if (! $confirmDestructive && collect($steps)->contains(static fn (ChangeStep $s): bool => $s->level === ChangeStep::DESTRUCTIVE)) {
            throw new BusinessRuleException(__('apps.update.errors.confirm'));
        }

        $this->target = $plan->target;
        $dump = $this->backup->create();
        $this->backup->backupFiles($dump);
        $this->done = [];

        try {
            $this->run($app, $steps, $user);
        } catch (Throwable $e) {
            activity('apps')->causedBy($user)->performedOn($app)->event('update_partial')
                ->withProperties(['version' => $plan->target['app']['version'] ?? null, 'done' => $this->done, 'error' => $e->getMessage(), 'backup' => basename($dump)])
                ->log(__('apps.update.log_partial', ['name' => (string) $app->name]));

            throw new BusinessRuleException(__('apps.update.errors.partial', [
                'error' => $e->getMessage(),
                'count' => count($this->done),
                'backup' => basename($dump),
            ]), previous: $e);
        }

        $version = $plan->target['app']['version'] ?? null;
        $app->forceFill(['version' => $version])->save();
        AppBaseline::query()->create([
            'app_id' => $app->id,
            'kind' => AppBaseline::UPDATED,
            'version' => $version,
            'definition' => $plan->target,
            'installed_by' => $user->id,
        ]);

        activity('apps')->causedBy($user)->performedOn($app)->event('updated')
            ->withProperties(['from' => $plan->fromVersion, 'version' => $version, 'steps' => $this->done, 'backup' => basename($dump)])
            ->log(__('apps.update.log', ['name' => (string) $app->name, 'version' => (string) ($version ?? '–')]));

        return ['steps' => $this->done, 'backup' => basename($dump)];
    }

    /** @param  list<ChangeStep>  $steps */
    private function run(App $app, array $steps, User $user): void
    {
        $of = static fn (string $kind, string $action): array => array_values(array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === $kind && $s->action === $action));

        // 1. App
        foreach ($of('app', ChangeStep::UPDATE) as $step) {
            $app->fill(self::values($step->changes))->save();
            $this->done[] = $step->label;
        }

        // 2a. Angebundene Tabellen zuerst: Verknüpfungen eigener Tabellen dürfen auf sie zeigen.
        $this->externalTables($app, $steps);

        // 2b. Neue Tabellen samt Feldern (wie beim Installieren).
        $this->createTables($app, $of('table', ChangeStep::CREATE));

        // 3. Neue Felder in bestehenden Tabellen: einfache, Verknüpfungen, Übernahmen und „Zählen je“, berechnete.
        $this->createFields($of('field', ChangeStep::CREATE));

        // 4. Felder ändern (Paket- und gewählte Konfliktänderungen zusammen). Nummernkreise zuerst: Kurzzeichen für neue
        // Wahlen ihres Gruppenfelds (N4) müssen stehen, bevor das Auswahlfeld die Wahlen bekommt.
        $updates = $this->merged($of('field', ChangeStep::UPDATE));
        $isNumber = static fn (ChangeStep $s): bool => ($s->target['type'] ?? null) === FieldType::Number->value;
        $updates = [...array_filter($updates, $isNumber), ...array_filter($updates, static fn (ChangeStep $s): bool => ! $isNumber($s))];

        foreach ($updates as $step) {
            $this->service->updateField($this->field($step->key), array_diff_key(self::values($step->changes), ['options' => true]) + self::options($step->changes));
            $this->done[] = $step->label;
        }

        // 5. Tabellen ändern, dann Freigaben.
        foreach ($this->merged($of('table', ChangeStep::UPDATE)) as $step) {
            $table = $this->table($step->key);
            $values = self::values($step->changes);
            $data = Arr::only($values, ['label', 'description', 'icon', 'is_active', 'export_enabled', 'import_enabled']);

            if (array_key_exists('rules', $values)) {
                $data['validation_rules'] = (array) $values['rules'];
            }

            if (array_key_exists('details', $values)) {
                $this->refs->refresh();
                $data['detail_field_ids'] = array_values(array_filter(array_map(fn (string $ref): ?int => $this->refs->fieldId($ref), (array) $values['details'])));
            }

            if ($data !== []) {
                $this->service->updateTable($table, $data);
            }

            if (array_key_exists('is_shared', $values) || array_key_exists('shared_fields', $values)) {
                $shared = collect((array) ($step->target['fields'] ?? []))->filter(static fn (array $f): bool => ! empty($f['is_shared']))->pluck('name')->all();
                $this->service->updateInterface($table->refresh(), (bool) ($step->target['is_shared'] ?? false), $shared);
            }

            $this->done[] = $step->label;
        }

        // 6. Tabellenverknüpfungen, Auswertungen, Menüs (Eltern vor Kindern), Handbuch.
        $this->objects($app, $steps, $user);

        // 7. Entfernen: Menüs (Kinder zuerst), Auswertungen, Tabellenverknüpfungen, Handbuch.
        $this->removeObjects($app, $steps);

        // 8. Deaktivieren (Daten bleiben), 9. Löschen – Felder vor Tabellen.
        foreach ([ChangeStep::DEACTIVATE, ChangeStep::DELETE] as $action) {
            foreach ([...$of('field', $action), ...$of('table', $action)] as $step) {
                $step->kind === 'field' ? $this->removeField($step) : $this->removeTable($step);
                $this->done[] = $step->label;
            }
        }
    }

    /** @param  list<ChangeStep>  $steps */
    private function objects(App $app, array $steps, User $user): void
    {
        $of = fn (string $kind, string $action): array => $this->merged(array_values(array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === $kind && $s->action === $action)));

        $this->refs->refresh();

        foreach ($of('report_link', ChangeStep::CREATE) as $step) {
            ReportLink::query()->create(['uuid' => $step->key, ...$this->objects->link($step->target)]);
            $this->done[] = $step->label;
        }

        foreach ($of('report_link', ChangeStep::UPDATE) as $step) {
            $columns = ['label' => ['label'], 'source' => ['source_table_id', 'source_field_id'], 'target_table' => ['target_table_id'], 'target_field' => ['target_field_id']];
            $data = Arr::only($this->objects->link($step->target), array_merge(...array_map(static fn (array $c): array => $columns[$c['attr']] ?? [], $step->changes)));
            ReportLink::query()->where('uuid', $step->key)->firstOrFail()->update($data);
            $this->done[] = $step->label;
        }

        $this->refs->refresh();

        foreach ($of('report', ChangeStep::CREATE) as $step) {
            Report::query()->create([
                'uuid' => $step->key,
                'name' => $step->target['name'],
                'description' => $step->target['description'] ?? null,
                'owner_id' => $user->id,
                'is_shared' => (bool) ($step->target['is_shared'] ?? false),
                'definition' => $this->objects->reportDefinition((array) $step->target['definition']),
            ]);
            $this->done[] = $step->label;
        }

        foreach ($of('report', ChangeStep::UPDATE) as $step) {
            $values = self::values($step->changes);

            if (array_key_exists('definition', $values)) {
                $values['definition'] = $this->objects->reportDefinition((array) $values['definition']);
            }

            Report::query()->where('uuid', $step->key)->firstOrFail()->update(Arr::only($values, ['name', 'description', 'is_shared', 'definition']));
            $this->done[] = $step->label;
        }

        // Menüs: Eltern vor Kindern (Tiefe des Pfads).
        $depth = static fn (ChangeStep $s): int => substr_count((string) ($s->target['path'] ?? ''), ' › ');
        $creates = $of('menu', ChangeStep::CREATE);
        usort($creates, static fn (ChangeStep $a, ChangeStep $b): int => $depth($a) <=> $depth($b));
        $menus = [];

        foreach ($creates as $step) {
            $menu = $step->target;
            $menus[(string) $menu['path']] = (int) Menu::query()->create([
                'uuid' => $step->key,
                'app_id' => $app->id,
                'parent_id' => $this->menuParent($menu['parent'] ?? null, $menus),
                'label' => $menu['label'],
                'icon' => $menu['icon'] ?? null,
                'permission_name' => $menu['permission_name'] ?? null,
                'sort_order' => (int) ($menu['sort_order'] ?? 0),
                'is_active' => (bool) ($menu['is_active'] ?? true),
                ...$this->objects->menuTarget((array) $menu['target'], [], $menus),
            ])->id;
            $this->done[] = $step->label;
        }

        $this->refs->refresh();

        foreach ($of('menu', ChangeStep::UPDATE) as $step) {
            $values = self::values($step->changes);
            $data = Arr::only($values, ['label', 'icon', 'permission_name', 'sort_order', 'is_active']);

            if (array_key_exists('parent', $values)) {
                $data['parent_id'] = $this->menuParent($values['parent'] === null ? null : (string) $values['parent'], $menus);
            }

            if (array_key_exists('target', $values)) {
                $data = [...$data, ...$this->objects->menuTarget((array) $values['target'], [], $menus)];
            }

            Menu::query()->where('uuid', $step->key)->firstOrFail()->update($data);
            $this->done[] = $step->label;
        }

        foreach ([...$of('manual', ChangeStep::CREATE), ...$of('manual', ChangeStep::UPDATE)] as $step) {
            AppManual::query()->updateOrCreate(['app_id' => $app->id, 'locale' => $step->key], ['content' => (string) ($step->target['content'] ?? '')]);
            $this->done[] = $step->label;
        }

        $this->roles($app, $of('role', ChangeStep::CREATE), $of('role', ChangeStep::UPDATE));
        $this->tasks($app, $of('task', ChangeStep::CREATE), $of('task', ChangeStep::UPDATE), $user);
        $this->documents($app, $of('document', ChangeStep::CREATE), $of('document', ChangeStep::UPDATE));
    }

    /**
     * Dokumentvorlagen (B-124, D1d): neue anlegen, geänderte Eigenschaften übernehmen; danach prüfen (Hinweis bei Fehlern).
     *
     * @param  list<ChangeStep>  $creates
     * @param  list<ChangeStep>  $updates
     */
    private function documents(App $app, array $creates, array $updates): void
    {
        $this->installer->takeNotes();

        foreach ($creates as $step) {
            $this->installer->createDocumentTemplate($app, [...$step->target, 'key' => $step->key]);
            $this->done[] = $step->label;
        }

        foreach ($updates as $step) {
            $template = DocumentTemplate::query()->where('uuid', $step->key)->firstOrFail();
            $values = self::values($step->changes);
            $data = Arr::only($values, ['name', 'recipient', 'body', 'file_name', 'language_field', 'file_field', 'email_to', 'email_subject', 'email_body', 'email_reply_to', 'qr_bill', 'formats', 'is_active', 'sort_order']);

            if (array_key_exists('table', $values)) {
                $data['custom_table_id'] = CustomTable::query()->where('name', (string) $values['table'])->firstOrFail()->id;
            }

            $template->update($data);
            $this->installer->checkDocumentTemplate($template, $template->customTable()->firstOrFail());
            $this->done[] = $step->label;
        }

        // Prüfhinweise zu den Vorlagen als eigene Zeilen im Ergebnis.
        foreach ($this->installer->takeNotes() as $note) {
            $this->done[] = $note;
        }
    }

    /**
     * Rollen: neue mit Präfix bei vergebenem Namen (wie beim Installieren); Änderungen als Differenz – Rechte ausserhalb
     * der App, die nicht im Paket stehen, bleiben.
     *
     * @param  list<ChangeStep>  $creates
     * @param  list<ChangeStep>  $updates
     */
    private function roles(App $app, array $creates, array $updates): void
    {
        $permissions = static fn (array $names) => Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->get();

        foreach ($creates as $step) {
            $role = Role::query()->create(['uuid' => $step->key, 'name' => $this->installer->roleName((string) $step->target['name'], (string) ($app->prefix ?? '')), 'guard_name' => 'web', 'app_id' => $app->id]);
            $role->syncPermissions($permissions((array) ($step->target['permissions'] ?? [])));
            $role->hiddenMenus()->sync($this->menuIds((array) ($step->target['hidden_menus'] ?? [])));
            $this->done[] = $step->label;
        }

        foreach ($updates as $step) {
            $role = Role::query()->where('uuid', $step->key)->firstOrFail();

            foreach ($step->changes as ['attr' => $attr, 'from' => $from, 'to' => $to]) {
                match ($attr) {
                    'name' => $role->update(['name' => (string) $to]),
                    'permissions' => (function () use ($role, $from, $to, $permissions): void {
                        $role->givePermissionTo($permissions(array_values(array_diff((array) $to, (array) $from))));

                        foreach ($permissions(array_values(array_diff((array) $from, (array) $to))) as $permission) {
                            $role->revokePermissionTo($permission);
                        }
                    })(),
                    'hidden_menus' => (function () use ($role, $from, $to): void {
                        $role->hiddenMenus()->syncWithoutDetaching($this->menuIds(array_values(array_diff((array) $to, (array) $from))));
                        $role->hiddenMenus()->detach($this->menuIds(array_values(array_diff((array) $from, (array) $to))));
                    })(),
                    default => null,
                };
            }

            $this->done[] = $step->label;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<ChangeStep>  $creates
     * @param  list<ChangeStep>  $updates
     */
    private function tasks(App $app, array $creates, array $updates, User $user): void
    {
        $parameters = function (array $task): array {
            $resolved = $this->installer->taskParameters((array) ($task['parameters'] ?? []));

            if ($resolved === null) {
                throw new BusinessRuleException(__('apps.update.notes.task_target'));
            }

            return $resolved;
        };

        foreach ($creates as $step) {
            ScheduledTask::query()->create([
                'uuid' => $step->key,
                'app_id' => $app->id,
                'name' => $step->target['name'] ?? null,
                'type' => $step->target['type'],
                'parameters' => $parameters($step->target),
                'frequency' => $step->target['frequency'],
                'interval_hours' => $step->target['interval_hours'] ?? null,
                'at_time' => $step->target['at_time'] ?? '03:00',
                'weekday' => $step->target['weekday'] ?? null,
                'is_active' => (bool) ($step->target['is_active'] ?? true),
                'created_by' => $user->id,
            ]);
            $this->done[] = $step->label;
        }

        foreach ($updates as $step) {
            $values = self::values($step->changes);

            if (array_key_exists('parameters', $values)) {
                $values['parameters'] = $parameters($step->target);
            }

            ScheduledTask::query()->where('uuid', $step->key)->firstOrFail()
                ->update(Arr::only($values, ['name', 'type', 'parameters', 'frequency', 'interval_hours', 'at_time', 'weekday', 'is_active']));
            $this->done[] = $step->label;
        }
    }

    /**
     * Angebundene Tabellen: neue wie beim Installieren (inaktiv, mit Verbindung gleichen Namens abgeglichen); Änderungen
     * nur auf der Seite von SAFT – Bezeichnungen, Merkmale der Spalten, Schreibrechte, Export. Quelle, Verbindung und
     * Schlüssel bleiben (Table-Connector).
     *
     * @param  list<ChangeStep>  $steps
     */
    private function externalTables(App $app, array $steps): void
    {
        foreach (array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === 'external_table' && $s->action === ChangeStep::CREATE) as $step) {
            $this->installer->external($app, $step->target);
            $this->done[] = $step->label;
        }

        foreach ($this->merged(array_values(array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === 'external_table' && $s->action === ChangeStep::UPDATE))) as $step) {
            $table = CustomTable::query()->where('uuid', $step->key)->with('fields')->firstOrFail();
            $values = self::values($step->changes);
            $data = Arr::only($values, ['label', 'description', 'icon', 'export_enabled']);

            if (array_key_exists('allow', $values)) {
                $data = [...$data, 'allow_create' => (bool) ($values['allow']['create'] ?? false), 'allow_update' => (bool) ($values['allow']['update'] ?? false), 'allow_delete' => (bool) ($values['allow']['delete'] ?? false)];
            }

            if ($data !== []) {
                $table->update($data);
            }

            if (array_key_exists('fields', $values)) {
                foreach ((array) $values['fields'] as $field) {
                    $table->fields->firstWhere('uuid', $field['key'] ?? null)
                        ?->update(Arr::only($field, ['label', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form', 'is_active', 'sort_order']));
                }
            }

            $this->done[] = $step->label;
        }
    }

    /**
     * Menüpfade des Pakets → IDs (über den Schlüssel im Paket, sonst den Pfad in SAFT).
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    private function menuIds(array $paths): array
    {
        return array_values(array_filter(array_map(fn (string $path): ?int => $this->menuParent($path, []), $paths)));
    }

    /**
     * Elternmenü zum Pfad im Paket: eben angelegt, sonst über den Schlüssel des Pakets (auch wenn die Gruppe erst
     * umbenannt wird), sonst über den Pfad in SAFT (z. B. eine Systemgruppe).
     *
     * @param  array<string, int>  $menus
     */
    private function menuParent(?string $path, array $menus): ?int
    {
        if ($path === null) {
            return null;
        }

        $key = collect((array) ($this->target['menus'] ?? []))->firstWhere('path', $path)['key'] ?? null;
        $id = $menus[$path] ?? ($key === null ? null : Menu::query()->where('uuid', $key)->value('id'));

        return $id !== null ? (int) $id : $this->objects->menuParent($path);
    }

    /** @param  list<ChangeStep>  $steps */
    private function removeObjects(App $app, array $steps): void
    {
        $of = static fn (string $kind): array => array_values(array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === $kind && $s->action === ChangeStep::DELETE));
        $menus = Menu::query()->whereIn('uuid', array_map(static fn (ChangeStep $s): string => $s->key, $of('menu')))->get();

        // Kinder vor Eltern: zuerst Einträge, deren Eltern nicht ebenfalls entfernt werden … rückwärts nach Tiefe.
        $level = static function (Menu $menu) use ($menus): int {
            for ($depth = 0, $current = $menu; $current !== null && $depth < 10; $depth++) {
                $current = $menus->firstWhere('id', $current->parent_id) ?? ($current->parent_id === null ? null : Menu::query()->find($current->parent_id));
            }

            return $depth;
        };

        foreach ($menus->sortByDesc($level) as $menu) {
            $menu->delete();
            $this->done[] = References::defaultLabel($menu->getTranslations('label'));
        }

        foreach ($of('report') as $step) {
            Report::query()->where('uuid', $step->key)->first()?->delete();
            $this->done[] = $step->label;
        }

        foreach ($of('report_link') as $step) {
            ReportLink::query()->where('uuid', $step->key)->first()?->delete();
            $this->done[] = $step->label;
        }

        foreach ($of('manual') as $step) {
            AppManual::query()->where('app_id', $app->id)->where('locale', $step->key)->delete();
            $this->done[] = $step->label;
        }

        foreach ($of('role') as $step) {
            Role::query()->where('uuid', $step->key)->first()?->delete();
            $this->done[] = $step->label;
        }

        foreach ($of('task') as $step) {
            ScheduledTask::query()->where('uuid', $step->key)->first()?->delete();
            $this->done[] = $step->label;
        }

        foreach ($of('document') as $step) {
            DocumentTemplate::query()->where('uuid', $step->key)->first()?->delete();
            $this->done[] = $step->label;
        }

        // Angebundene Tabellen nur deaktivieren.
        foreach (array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === 'external_table' && $s->action === ChangeStep::DEACTIVATE) as $step) {
            CustomTable::query()->where('uuid', $step->key)->first()?->update(['is_active' => false]);
            $this->done[] = $step->label;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  list<ChangeStep>  $steps */
    private function createTables(App $app, array $steps): void
    {
        if ($steps === []) {
            return;
        }

        $tables = array_map(static fn (ChangeStep $s): array => [
            ...Arr::except($s->target, ['key', 'is_shared']),
            'fields' => array_map(static fn (array $f): array => Arr::except($f, ['key', 'is_shared']), (array) $s->target['fields']),
        ], $steps);
        $this->importer->import(['format' => SchemaExporter::FORMAT, 'tables' => $tables], app: $app);

        foreach ($steps as $step) {
            $model = CustomTable::query()->where('name', (string) $step->target['name'])->firstOrFail();
            $model->forceFill(['uuid' => $step->key, 'is_shared' => (bool) ($step->target['is_shared'] ?? false)])->saveQuietly();

            foreach ((array) $step->target['fields'] as $field) {
                CustomField::query()->where('custom_table_id', $model->id)->where('name', $field['name'])
                    ->update(['uuid' => $field['key'], 'is_shared' => (bool) ($field['is_shared'] ?? false)]);
            }

            $this->done[] = $step->label;
        }
    }

    /** @param  list<ChangeStep>  $steps */
    /** Rang berechneter Felder beim Anlegen: zuletzt, mit Wiederholung, solange es vorangeht. */
    private const int COMPUTED_RANK = 4;

    private function createFields(array $steps): void
    {
        $order = static fn (ChangeStep $s): int => match (true) {
            // Nummernkreis mit Bezugsdatum bzw. Gruppe (B-135): nach dem Datums- bzw. Auswahlfeld, vor den Verknüpfungen
            // (die ihn anzeigen dürfen).
            ($s->target['type'] ?? null) === FieldType::Number->value && (! empty($s->target['options'][NumberPattern::DATE_FIELD]) || ! empty($s->target['options'][NumberPattern::GROUP_FIELD])) => 1,
            ($s->target['type'] ?? null) === FieldType::Relation->value => 2,
            ($s->target['type'] ?? null) === FieldType::Computed->value => self::COMPUTED_RANK,
            // Bezieht sich auf eine Verknüpfung: Übernehmen aus, fortlaufend je …
            ! empty($s->target['options'][CopySource::OPTION]) || ! empty($s->target['options'][SequenceSuggester::SCOPE]) => 3,
            default => 0,
        };
        usort($steps, static fn (ChangeStep $a, ChangeStep $b): int => $order($a) <=> $order($b));
        $pending = $steps;

        // Berechnete Felder können auf andere neue berechnete Felder aufbauen: wiederholen, solange es vorangeht.
        while ($pending !== []) {
            $failed = [];
            $error = null;

            foreach ($pending as $step) {
                try {
                    $field = $this->service->addField($this->table((string) $step->table), Arr::except($step->target, ['key', 'is_shared']));
                    $field->forceFill(['uuid' => $step->key])->saveQuietly();
                    $this->done[] = $step->label;
                } catch (BusinessRuleException $e) {
                    if ($order($step) !== self::COMPUTED_RANK) {
                        throw $e;
                    }

                    $failed[] = $step;
                    $error = $e;
                }
            }

            if ($failed !== [] && count($failed) === count($pending)) {
                throw $error;
            }

            $pending = $failed;
        }
    }

    private function removeField(ChangeStep $step): void
    {
        $field = CustomField::query()->where('uuid', $step->key)->first();

        if ($field === null) {
            return;
        }

        if ($field->is_active) {
            $field = $this->service->updateField($field, ['is_active' => false]);
        }

        if ($step->action === ChangeStep::DELETE) {
            $this->service->dropField($field->refresh(), $field->name);
        }
    }

    private function removeTable(ChangeStep $step): void
    {
        $table = CustomTable::query()->where('uuid', $step->key)->first();

        if ($table === null) {
            return;
        }

        if ($table->is_active) {
            $table = $this->service->updateTable($table, ['is_active' => false]);
        }

        if ($step->action === ChangeStep::DELETE) {
            $this->service->dropTable($table->refresh(), $table->name);
        }
    }

    /**
     * Änderungen desselben Objekts (Paket und gewählter Konflikt) zu einem Schritt zusammenführen.
     *
     * @param  list<ChangeStep>  $steps
     * @return list<ChangeStep>
     */
    private function merged(array $steps): array
    {
        $byKey = [];

        foreach ($steps as $step) {
            $byKey[$step->key] = isset($byKey[$step->key])
                ? $byKey[$step->key]->with(['changes' => [...$byKey[$step->key]->changes, ...$step->changes]])
                : $step;
        }

        return array_values($byKey);
    }

    /**
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes
     * @return array<string, mixed>
     */
    private static function values(array $changes): array
    {
        return array_column($changes, 'to', 'attr');
    }

    /**
     * Optionen vollständig aus dem Paket (ohne Syntaxbaum – er entsteht aus der Formel).
     *
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes
     * @return array<string, mixed>
     */
    private static function options(array $changes): array
    {
        foreach ($changes as $change) {
            if ($change['attr'] === 'options') {
                return ['options' => (array) $change['to']];
            }
        }

        return [];
    }

    private function table(string $key): CustomTable
    {
        return CustomTable::query()->where('uuid', $key)->with('fields')->firstOrFail();
    }

    private function field(string $key): CustomField
    {
        return CustomField::query()->where('uuid', $key)->firstOrFail();
    }
}
