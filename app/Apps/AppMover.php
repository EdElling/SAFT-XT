<?php

declare(strict_types=1);

namespace App\Apps;

use App\Apps\Definition\DefinitionParts;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tabellen in eine andere App verschieben (B-107, Etappe 1b): mit ihren Menüeinträgen, Auswertungen,
 * Tabellenverknüpfungen und Aufgaben. Geprüft wird der Endzustand – Verweise innerhalb der Auswahl sind in Ordnung,
 * Verweise über die App-Grenze verhindern das Verschieben (Schnittstellen kommen später). Daten, Dateien und technische
 * Namen bleiben unverändert.
 */
final class AppMover
{
    /** Rollenrechte: neue Rolle in der Ziel-App (mit den Mitgliedern) bzw. Rechte entfernen. */
    public const string ROLE_NEW = 'new';

    public const string ROLE_DROP = 'drop';

    /** Zielgruppe: neue Gruppe mit dem Namen der bisherigen. */
    public const string GROUP_NEW = 'new';

    public function __construct(private readonly DefinitionParts $exporter) {}

    /**
     * Was beim Verschieben geschieht und was es verhindert.
     *
     * @param  list<string>  $tableNames
     * @return array{
     *     tables: list<string>,
     *     blockers: list<string>,
     *     suggested: list<string>,
     *     reports: list<string>,
     *     menus: int,
     *     roles: array<int, array{name: string, permissions: int, users: int}>
     * }
     */
    public function plan(array $tableNames, App $target): array
    {
        $tables = CustomTable::query()->with('fields')->whereIn('name', $tableNames)->get()->keyBy('name');
        $final = $this->finalApps($tables->keys()->all(), $target);
        $labels = CustomTable::query()->get(['name', 'label'])->mapWithKeys(static fn (CustomTable $t): array => [$t->name => (string) $t->label])->all();
        $blockers = [];
        $suggested = [];

        // Freigegebene Tabellen (Schnittstellen, Etappe 5): Verknüpfungen darauf dürfen über die App-Grenze reichen,
        // solange die freigegebene Tabelle selbst in ihrer App bleibt.
        $shared = CustomTable::query()->where('is_shared', true)->pluck('name')->all();

        foreach ($this->references() as [$kind, $from, $to, $what]) {
            $inside = $tables->has($from) || $tables->has($to);

            if (! $inside || ($final[$from] ?? null) === ($final[$to] ?? null)) {
                continue;
            }

            if ($kind === 'relation' && in_array($to, $shared, true) && ! $tables->has($to)) {
                continue;
            }

            $blockers[] = __('apps.move.blocker_'.$kind, ['what' => $what, 'from' => $labels[$from] ?? $from, 'to' => $labels[$to] ?? $to]);
            $suggested[] = $tables->has($from) ? $to : $from;
        }

        $reports = [];

        foreach (Report::query()->get() as $report) {
            $used = $this->exporter->reportUses($report)['tables'];

            if (array_intersect($used, $tables->keys()->all()) === []) {
                continue;
            }

            if (count(array_unique(array_map(static fn (string $t): ?int => $final[$t] ?? null, $used))) > 1) {
                $blockers[] = __('apps.move.blocker_report', ['report' => $report->name]);
                $suggested = [...$suggested, ...array_diff($used, $tables->keys()->all())];
            } elseif ($tables->has($used[0] ?? '')) {
                $reports[] = (string) $report->name;
            }
        }

        return [
            'tables' => $tables->keys()->sort()->values()->all(),
            'blockers' => array_values(array_unique($blockers)),
            // Nur Tabellen, die nicht schon in der Ziel-App liegen.
            'suggested' => array_values(array_filter(array_unique($suggested), static fn (string $t): bool => ($final[$t] ?? null) !== $target->id)),
            'reports' => $reports,
            'menus' => $this->dataEntries($tables->keys()->all())->count(),
            'roles' => $this->roleRights($tables->keys()->all(), $target),
        ];
    }

    /**
     * Alle Tabellen, die mit der Auswahl zusammenhängen (Verknüpfungen, Tabellenverknüpfungen, Auswertungen) – ohne
     * solche, die schon in der Ziel-App liegen.
     *
     * @param  list<string>  $tableNames
     * @return list<string>
     */
    public function connected(array $tableNames, App $target): array
    {
        $edges = [];

        foreach ($this->references() as [, $from, $to]) {
            $edges[$from][] = $to;
            $edges[$to][] = $from;
        }

        foreach (Report::query()->get() as $report) {
            $used = $this->exporter->reportUses($report)['tables'];

            foreach ($used as $a) {
                foreach ($used as $b) {
                    if ($a !== $b) {
                        $edges[$a][] = $b;
                    }
                }
            }
        }

        $inTarget = CustomTable::query()->where('app_id', $target->id)->pluck('name')->all();
        $result = [];
        $queue = $tableNames;

        while ($queue !== []) {
            $name = array_shift($queue);

            if (in_array($name, $result, true) || in_array($name, $inTarget, true)) {
                continue;
            }

            $result[] = $name;
            $queue = [...$queue, ...($edges[$name] ?? [])];
        }

        sort($result);

        return $result;
    }

    /**
     * Verschieben. Zielgruppe: ID einer Gruppe der Ziel-App, GROUP_NEW oder – beim Verschieben einer ganzen Menügruppe –
     * null (die Gruppe zieht mit). Rollen: Rollen-ID => ROLE_NEW, ROLE_DROP oder ID einer Rolle der Ziel-App.
     *
     * @param  list<string>  $tableNames
     * @param  array<int|string, int|string>  $roleChoices
     * @param  array<int|string, string>  $roleNames  Name der neuen Rolle je Rollen-ID
     *
     * @throws BusinessRuleException wenn Verweise über die App-Grenze bleiben würden
     */
    public function move(array $tableNames, App $target, int|string|null $group, array $roleChoices, array $roleNames = [], ?Menu $movedGroup = null, ?User $user = null): void
    {
        $plan = $this->plan($tableNames, $target);

        if ($plan['blockers'] !== []) {
            throw new BusinessRuleException(__('apps.move.blocked', ['reasons' => implode('; ', $plan['blockers'])]));
        }

        if ($plan['tables'] === []) {
            throw new BusinessRuleException(__('apps.move.nothing'));
        }

        DB::transaction(function () use ($plan, $target, $group, $roleChoices, $roleNames, $movedGroup, $user): void {
            $names = $plan['tables'];
            $tables = CustomTable::query()->whereIn('name', $names)->get();
            $ids = $tables->pluck('id')->all();
            $sources = $tables->pluck('app_id')->filter()->unique()->values()->all();

            foreach ($tables as $table) {
                $table->update(['app_id' => $target->id]);
            }

            foreach (ReportLink::query()->whereIn('source_table_id', $ids)->get() as $link) {
                $link->save();
            }

            $reports = Report::query()->get()->filter(fn (Report $r): bool => in_array((int) (((array) $r->definition)['base'] ?? 0), $ids, true));

            foreach ($reports as $report) {
                $report->save();
            }

            foreach (ScheduledTask::query()->where('is_system', false)->get() as $task) {
                if (in_array((int) (((array) ($task->parameters ?? []))['table_id'] ?? 0), $ids, true)) {
                    $task->save();
                }
            }

            $this->moveMenus($names, $reports->pluck('id')->all(), $target, $group, $movedGroup);
            $this->moveRoleRights($names, $target, $roleChoices, $roleNames);

            activity('apps')
                ->causedBy($user)
                ->performedOn($target)
                ->event('tables_moved')
                ->withChanges(['attributes' => [
                    'tabellen' => $names,
                    'auswertungen' => $reports->pluck('name')->values()->all(),
                    'von' => App::query()->whereKey($sources)->get()->map(static fn (App $a): string => (string) $a->name)->all(),
                    'nach' => (string) $target->name,
                ]])
                ->log('tables_moved');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Vorschlag für den Namen einer neuen Rolle in der Ziel-App, z. B. „vertrieb-lager“. */
    public static function newRoleName(Role $role, App $target): string
    {
        $suffix = $target->prefix ?: Str::slug((string) $target->name);
        $base = Str::limit($role->name.'-'.$suffix, 120, '');
        $name = $base;

        for ($n = 2; Role::query()->where('name', $name)->where('guard_name', 'web')->exists(); $n++) {
            $name = $base.'-'.$n;
        }

        return $name;
    }

    /**
     * Verweise zwischen Tabellen: [Art, von, nach, Bezeichnung].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function references(): array
    {
        $references = [];

        foreach (CustomField::query()->with('custom_table')->where('type', FieldType::Relation->value)->get() as $field) {
            $target = (string) ($field->optionsArray()['table'] ?? '');

            if ($target !== '' && $field->custom_table !== null) {
                $references[] = ['relation', $field->custom_table->name, $target, (string) $field->label];
            }
        }

        foreach (ReportLink::query()->with(['sourceTable', 'targetTable'])->get() as $link) {
            if ($link->sourceTable !== null && $link->targetTable !== null) {
                $references[] = ['link', $link->sourceTable->name, $link->targetTable->name, (string) $link->label];
            }
        }

        return $references;
    }

    /**
     * App jeder Tabelle nach dem Verschieben.
     *
     * @param  list<string>  $moving
     * @return array<string, int|null>
     */
    private function finalApps(array $moving, App $target): array
    {
        return CustomTable::query()->get(['name', 'app_id'])
            ->mapWithKeys(static fn (CustomTable $t): array => [$t->name => in_array($t->name, $moving, true) ? $target->id : $t->app_id])
            ->all();
    }

    /** @param  list<string>  $tables */
    private function dataEntries(array $tables): Collection
    {
        return Menu::query()->where('route', DataMenu::ROUTE)->get()
            ->filter(static fn (Menu $m): bool => in_array((string) (($m->route_parameters ?? [])['table'] ?? ''), $tables, true))
            ->values();
    }

    /**
     * Rollen anderer Apps mit Datenrechten auf die Tabellen.
     *
     * @param  list<string>  $tables
     * @return array<int, array{name: string, permissions: int, users: int}>
     */
    private function roleRights(array $tables, App $target): array
    {
        $roles = [];

        foreach (Role::query()->with('permissions')->withCount('users')->where(static fn ($q) => $q->whereNull('app_id')->orWhere('app_id', '!=', $target->id))->get() as $role) {
            $count = $role->permissions->filter(static fn ($p): bool => in_array(self::tableOf($p->name), $tables, true))->count();

            if ($count > 0) {
                $roles[$role->id] = ['name' => $role->name, 'permissions' => $count, 'users' => (int) $role->users_count];
            }
        }

        return $roles;
    }

    private static function tableOf(string $permission): ?string
    {
        return str_starts_with($permission, DataPermissions::PREFIX) ? explode('.', substr($permission, strlen(DataPermissions::PREFIX)))[0] : null;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<int>  $reportIds
     */
    private function moveMenus(array $tables, array $reportIds, App $target, int|string|null $group, ?Menu $movedGroup): void
    {
        $entries = $this->dataEntries($tables)->merge(
            Menu::query()->where('route', ReportResource::VIEW_ROUTE)->get()
                ->filter(static fn (Menu $m): bool => in_array((int) (($m->route_parameters ?? [])['record'] ?? 0), $reportIds, true)),
        );
        $oldParents = $entries->pluck('parent_id')->filter()->unique()->values()->all();

        if ($movedGroup !== null) {
            // Die ganze Gruppe zieht mit; Einträge der Tabellen ausserhalb kommen in sie hinein.
            $inside = $this->subtree($movedGroup);
            Menu::query()->whereKey($inside)->update(['app_id' => $target->id]);
            $parent = $movedGroup;
        } else {
            $parent = $group === self::GROUP_NEW || $group === null
                ? $this->newGroup($entries, $target)
                : Menu::query()->where('app_id', $target->id)->findOrFail((int) $group);
            $inside = $this->subtree($parent);
        }

        $sort = (int) Menu::query()->where('parent_id', $parent->id)->max('sort_order');

        foreach ($entries as $entry) {
            if (in_array($entry->id, $inside, true)) {
                $entry->save();

                continue;
            }

            $entry->update(['parent_id' => $parent->id, 'sort_order' => $sort += 10]);
        }

        if ($movedGroup === null) {
            // Untergruppen der Zielgruppe gehören zur Ziel-App (Einträge können in ihnen liegen).
            Menu::query()->whereKey($this->subtree($parent))->whereNull('route')->update(['app_id' => $target->id]);
            app(DataMenu::class)->ensureDataModel($parent);
        }

        foreach ($oldParents as $id) {
            $this->removeIfEmpty(Menu::query()->find($id));
        }
    }

    /** Neue oberste Gruppe in der Ziel-App mit dem Namen der bisherigen Gruppe (gibt es sie dort schon: diese). */
    private function newGroup(Collection $entries, App $target): Menu
    {
        /** @var Menu|null $first */
        $first = $entries->first();
        // Name der obersten Gruppe (z. B. „Lager (Demo)“ statt der Untergruppe „Stammdaten“).
        $old = $first?->parent;

        while ($old?->parent !== null) {
            $old = $old->parent;
        }

        $label = $old?->getTranslations('label') ?: [...DataMenu::GROUP_LABELS, 'de' => DataMenu::GROUP_LABEL];

        return Menu::query()->whereNull('parent_id')->whereNull('route')->where('app_id', $target->id)->where('label->de', $label['de'] ?? '')->first()
            ?? Menu::create(['app_id' => $target->id, 'label' => $label, 'icon' => $old?->icon, 'sort_order' => (int) ($old?->sort_order ?? 500), 'is_active' => true]);
    }

    /** @return list<int> Gruppe und alle Einträge darunter */
    private function subtree(Menu $menu): array
    {
        $ids = [(int) $menu->id];
        $queue = [(int) $menu->id];

        while ($queue !== []) {
            $children = Menu::query()->whereIn('parent_id', $queue)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $ids = [...$ids, ...$children];
            $queue = $children;
        }

        return $ids;
    }

    /** Gruppe entfernen, wenn sie nur noch „Datenmodell“ oder nichts enthält; danach ihre Eltern prüfen. */
    private function removeIfEmpty(?Menu $group): void
    {
        if ($group === null || $group->route !== null) {
            return;
        }

        $children = Menu::query()->where('parent_id', $group->id)->get();

        if ($children->contains(static fn (Menu $c): bool => $c->route !== DataModelPage::ROUTE)) {
            return;
        }

        $parent = $group->parent;
        Menu::query()->whereKey($children->pluck('id'))->delete();
        $group->delete();
        $this->removeIfEmpty($parent);
    }

    /**
     * @param  list<string>  $tables
     * @param  array<int|string, int|string>  $choices
     * @param  array<int|string, string>  $names
     */
    private function moveRoleRights(array $tables, App $target, array $choices, array $names): void
    {
        foreach ($this->roleRights($tables, $target) as $roleId => $info) {
            $role = Role::query()->with(['permissions', 'users'])->findOrFail($roleId);
            $permissions = $role->permissions->filter(static fn ($p): bool => in_array(self::tableOf($p->name), $tables, true));
            $choice = $choices[$roleId] ?? self::ROLE_NEW;

            if ($choice !== self::ROLE_DROP) {
                $receiver = $choice === self::ROLE_NEW
                    ? Role::query()->create(['name' => trim((string) ($names[$roleId] ?? '')) ?: self::newRoleName($role, $target), 'guard_name' => 'web', 'app_id' => $target->id])
                    : Role::query()->where('app_id', $target->id)->findOrFail((int) $choice);

                $receiver->givePermissionTo($permissions);

                // Niemand verliert den Zugriff: die Mitglieder kommen mit.
                foreach ($role->users as $member) {
                    $member->assignRole($receiver);
                }
            }

            $role->revokePermissionTo($permissions);
        }
    }
}
