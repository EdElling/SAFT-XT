<?php

declare(strict_types=1);

namespace App\DynamicData\Usages;

use App\DynamicData\DataPermissions;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\Parser;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Schema\FieldType;
use App\Filament\Pages\PluginsPage;
use App\Filament\Resources\CustomTables\CustomTableResource;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\ReportLinks\ReportLinkResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\RecordListener;
use App\Plugins\PluginRegistry;
use App\Tasks\Types\SnapshotRefreshTask;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Wo eine Tabelle verwendet wird: Plugins, Auswertungen, Verknüpfungen von Auswertungen, Menüs, Verknüpfungen,
 * Formeln und Übernahmen anderer Tabellen, Rollen und geplante Aufgaben. Nur zur Information (Reiter „Verwendet in“,
 * Warnung beim Löschen); Links nur, wo die angemeldete Person das Ziel öffnen darf.
 */
final readonly class TableUsages
{
    public function __construct(private PluginRegistry $plugins, private Parser $parser) {}

    /** @return list<TableUsage> nach Bereich (TableUsage::AREAS) */
    public function of(CustomTable $table): array
    {
        return [
            ...$this->plugins($table),
            ...$this->reports($table),
            ...$this->reportLinks($table),
            ...$this->menus($table),
            ...$this->relations($table),
            ...$this->formulas($table),
            ...$this->copies($table),
            ...$this->roles($table),
            ...$this->tasks($table),
        ];
    }

    /** @return array<string, int> Bereich => Anzahl (nur Bereiche mit Verwendungen) */
    public function counts(CustomTable $table): array
    {
        $counts = [];

        foreach ($this->of($table) as $usage) {
            $counts[$usage->area] = ($counts[$usage->area] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return list<TableUsage> */
    private function plugins(CustomTable $table): array
    {
        $usages = [];

        foreach ($this->plugins->all() as $plugin) {
            $parts = [];

            if (array_key_exists($table->name, $plugin->requiredTables())) {
                $fields = $plugin->requiredTables()[$table->name];
                $parts[] = $fields === [] ? __('tables.usages.plugin_requires_table') : __('tables.usages.plugin_requires', ['fields' => implode(', ', $fields)]);
            }

            if (array_filter($plugin->recordListeners(), static fn (RecordListener $l): bool => $l->table === $table->name) !== []) {
                $parts[] = __('tables.usages.plugin_listens');
            }

            if (array_filter($plugin->dataActions(), static fn (DataAction $a): bool => $a->table === $table->name) !== []) {
                $parts[] = __('tables.usages.plugin_actions');
            }

            if ($parts !== []) {
                $usages[] = new TableUsage(TableUsage::PLUGIN, $plugin->label(), implode(' · ', $parts), PluginsPage::canAccess() ? PluginsPage::getUrl() : null);
            }
        }

        return $usages;
    }

    /** @return list<TableUsage> */
    private function reports(CustomTable $table): array
    {
        $fieldIds = $table->fields()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $usages = [];

        foreach (Report::query()->orderBy('name')->get() as $report) {
            $definition = (array) ($report->definition ?? []);
            $base = (int) ($definition['base'] ?? 0) === (int) $table->id;
            $joined = ! $base && array_intersect($fieldIds, array_map(
                static fn (mixed $f): int => is_array($f) ? (int) ($f['field'] ?? 0) : 0,
                (array) ($definition['fields'] ?? []),
            )) !== [];

            if ($base || $joined) {
                $usages[] = new TableUsage(
                    TableUsage::REPORT,
                    (string) $report->name,
                    __($base ? 'tables.usages.report_base' : 'tables.usages.report_joined'),
                    $this->link($report, ReportResource::class, 'view'),
                );
            }
        }

        return $usages;
    }

    /** @return list<TableUsage> */
    private function reportLinks(CustomTable $table): array
    {
        return ReportLink::query()
            ->where(static fn ($q) => $q->where('source_table_id', $table->id)->orWhere('target_table_id', $table->id))
            ->orderBy('id')
            ->get()
            ->map(fn (ReportLink $link): TableUsage => new TableUsage(
                TableUsage::REPORT_LINK,
                (string) $link->label,
                __((int) $link->source_table_id === (int) $table->id ? 'tables.usages.link_source' : 'tables.usages.link_target'),
                $this->link($link, ReportLinkResource::class),
            ))
            ->all();
    }

    /** @return list<TableUsage> */
    private function menus(CustomTable $table): array
    {
        $usages = [];

        foreach (Menu::query()->whereNotNull('route_parameters')->orderBy('sort_order')->get() as $menu) {
            if ((((array) $menu->route_parameters)['table'] ?? null) !== $table->name) {
                continue;
            }

            $usages[] = new TableUsage(TableUsage::MENU, $this->menuPath($menu), __($menu->is_active ? 'tables.usages.menu_active' : 'tables.usages.menu_inactive'), $this->link($menu, MenuResource::class));
        }

        return $usages;
    }

    private function menuPath(Menu $menu): string
    {
        $labels = [(string) $menu->label];
        $parent = $menu->parent;

        for ($depth = 0; $parent instanceof Menu && $depth < 10; $depth++) {
            array_unshift($labels, (string) $parent->label);
            $parent = $parent->parent;
        }

        return implode(' › ', $labels);
    }

    /** @return list<TableUsage> Felder anderer Tabellen, die auf diese Tabelle verweisen */
    private function relations(CustomTable $table): array
    {
        return $this->otherFields($table)
            ->filter(static fn (CustomField $f): bool => $f->type === FieldType::Relation && ($f->optionsArray()['table'] ?? null) === $table->name)
            ->map(fn (CustomField $f): TableUsage => $this->fieldUsage(TableUsage::RELATION, $f, __('tables.usages.relation', ['label' => (string) $f->label])))
            ->values()
            ->all();
    }

    /** @return list<TableUsage> berechnete Felder anderer Tabellen, die über diese als Kind-Tabelle zusammenfassen */
    private function formulas(CustomTable $table): array
    {
        $usages = [];

        foreach ($this->otherFields($table)->filter(static fn (CustomField $f): bool => $f->type === FieldType::Computed) as $field) {
            $formula = (string) ($field->optionsArray()['formula'] ?? '');

            try {
                $uses = $formula !== '' && self::aggregatesOver($this->parser->parse($formula), $table->name);
            } catch (Throwable) {
                $uses = false;
            }

            if ($uses) {
                $usages[] = $this->fieldUsage(TableUsage::FORMULA, $field, $formula);
            }
        }

        return $usages;
    }

    /** @param  array<mixed>  $node */
    private static function aggregatesOver(array $node, string $table): bool
    {
        if (isset($node['path']) && ($node['path'][0] ?? null) === $table) {
            return true;
        }

        // count(tabelle)
        if (in_array($node['call'] ?? null, FormulaAnalyzer::AGGREGATES, true) && (($node['args'][0]['field'] ?? null) === $table)) {
            return true;
        }

        foreach ($node as $child) {
            if (is_array($child) && self::aggregatesOver($child, $table)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<TableUsage> Felder anderer Tabellen, die einen Wert aus dieser Tabelle übernehmen */
    private function copies(CustomTable $table): array
    {
        $usages = [];

        foreach ($this->otherFields($table) as $field) {
            [$relation, $source] = CopySource::of($field) ?? [null, null];

            if ($relation === null) {
                continue;
            }

            $via = $field->custom_table?->fields->firstWhere('name', $relation);

            if ($via instanceof CustomField && ($via->optionsArray()['table'] ?? null) === $table->name) {
                $usages[] = $this->fieldUsage(TableUsage::COPY, $field, __('tables.usages.copy', ['field' => $source]));
            }
        }

        return $usages;
    }

    /** @return list<TableUsage> */
    private function roles(CustomTable $table): array
    {
        $names = DataPermissions::names($table->name);

        return Role::query()
            ->whereHas('permissions', static fn ($q) => $q->whereIn('name', $names))
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): TableUsage => new TableUsage(
                TableUsage::ROLE,
                (string) $role->name,
                implode(', ', $role->permissions->pluck('name')->intersect($names)
                    ->map(static fn (string $p): string => __('roles.actions.'.substr($p, strrpos($p, '.') + 1)))->values()->all()),
                $this->link($role, RoleResource::class),
            ))
            ->all();
    }

    /** @return list<TableUsage> */
    private function tasks(CustomTable $table): array
    {
        return ScheduledTask::query()->where('type', SnapshotRefreshTask::KEY)->orderBy('name')->get()
            ->filter(static fn (ScheduledTask $task): bool => (int) (((array) $task->parameters)['table_id'] ?? 0) === (int) $table->id)
            ->map(fn (ScheduledTask $task): TableUsage => new TableUsage(
                TableUsage::TASK,
                (string) $task->name,
                __('tasks.types.snapshot_refresh'),
                $this->link($task, ScheduledTaskResource::class),
            ))
            ->values()
            ->all();
    }

    /** @return Collection<int, CustomField> Felder aller anderen Tabellen (mit ihrer Tabelle) */
    private function otherFields(CustomTable $table): Collection
    {
        return CustomField::query()->with('custom_table.fields')->where('custom_table_id', '!=', $table->id)
            ->orderBy('custom_table_id')->orderBy('sort_order')->get();
    }

    private function fieldUsage(string $area, CustomField $field, string $detail): TableUsage
    {
        $owner = $field->custom_table;

        return new TableUsage($area, $owner?->label.' › '.$field->label.' ('.$owner?->name.'.'.$field->name.')', $detail, $owner === null ? null : $this->link($owner, CustomTableResource::class));
    }

    /** Link auf die Seite des Eintrags, wenn die Person sie öffnen darf. */
    private function link(Model $record, string $resource, string $page = 'edit'): ?string
    {
        try {
            $allowed = $page === 'view' ? $resource::canView($record) : $resource::canEdit($record);

            return $allowed ? $resource::getUrl($page, ['record' => $record]) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
