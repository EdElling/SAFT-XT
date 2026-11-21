<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\DynamicData\DataMenu;
use App\Filament\Support\RowIcons;
use App\Models\CustomTable;
use App\Plugins\Api\ApiManifest;
use App\Plugins\Api\DashboardWidget;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\Api\RecordListener;
use App\Plugins\Context\ContextBundle;
use App\Plugins\PluginCleanup;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginInspector;
use App\Plugins\PluginMenus;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Installierte Plugins: Version, Kompatibilität, Rechte, Menüvorschläge übernehmen (Administration › Plugins). */
class PluginsPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const string ROUTE = 'filament.admin.pages.plugins';

    protected static ?string $slug = 'plugins';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('plugins.view') ?? false;
    }

    public function getTitle(): string
    {
        return __('plugins.title');
    }

    public function content(Schema $schema): Schema
    {
        $cleanup = app(PluginCleanup::class);
        $menus = count($cleanup->orphanedMenus());
        $permissions = count($cleanup->orphanedPermissions());

        $resting = array_filter(app(PluginRegistry::class)->all(), static fn (SaftPlugin $plugin): bool => app(PluginStates::class)->status($plugin) === PluginStates::RESTING);

        return $schema->components([
            Text::make(__('plugins.intro')),
            Callout::make(__('plugins.dependencies.title'))
                ->description(__('plugins.dependencies.summary', ['plugins' => implode(', ', array_map(static fn (SaftPlugin $p): string => $p->label(), $resting))]))
                ->danger()
                ->visible($resting !== []),
            Callout::make(__('plugins.orphans.title'))
                ->description(__('plugins.orphans.summary', ['menus' => $menus, 'permissions' => $permissions]))
                ->warning()
                ->visible($menus + $permissions > 0),
            EmbeddedTable::make(),
        ]);
    }

    /** @return array<int, string> Recht-id => „In Rollen: …“ bzw. „Keiner Rolle zugewiesen“ */
    public static function permissionRoles(): array
    {
        $cleanup = app(PluginCleanup::class);
        $roles = $cleanup->rolesOfOrphanedPermissions();

        return collect($cleanup->orphanedPermissions())
            ->mapWithKeys(static fn (string $name, int $id): array => [$id => isset($roles[$id])
                ? __('plugins.orphans.in_roles', ['roles' => implode(', ', $roles[$id])])
                : __('plugins.orphans.no_roles')])
            ->all();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            // Entwickler-Kontext (AGENTS.md, API-Referenz, Schema der Fachtabellen) als ZIP – ohne Daten und Zugangsdaten.
            Action::make('developerContext')
                ->label(__('plugins.context.action'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => self::canManage())
                ->modalHeading(__('plugins.context.heading'))
                ->modalDescription(__('plugins.context.description'))
                ->modalSubmitActionLabel(__('plugins.context.submit'))
                ->schema(fn (): array => [
                    Text::make(__('plugins.context.tables'))->weight(FontWeight::Medium),
                    // Wie in der Rollenbearbeitung: nach Menügruppe, darin nach Anzeigename.
                    ...array_map(static fn (string $group, array $tables): Fieldset => Fieldset::make($group)
                        ->schema([
                            CheckboxList::make('tables.'.md5($group))
                                ->hiddenLabel()
                                ->options($tables)
                                ->default(array_keys($tables))
                                ->bulkToggleable()
                                ->columns(2),
                        ])
                        ->columns(1), array_keys($groups = self::contextTableGroups()), $groups),
                    Toggle::make('external')
                        ->label(__('plugins.context.external'))
                        ->helperText(__('plugins.context.external_help'))
                        ->default(true),
                ])
                ->action(function (array $data): StreamedResponse {
                    abort_unless(self::canManage(), 403);
                    $external = (bool) ($data['external'] ?? false);
                    $tables = [
                        ...array_values(array_map('strval', array_merge([], ...array_values(array_map(static fn (mixed $g): array => (array) $g, (array) ($data['tables'] ?? [])))))),
                        ...($external ? CustomTable::query()->where('source', '!=', CustomTable::SOURCE_DESIGNER)->pluck('name')->all() : []),
                    ];
                    $path = app(ContextBundle::class)->zip((string) tempnam(storage_path('app'), 'ctx'), $tables, $external);

                    activity('plugins')
                        ->event('context_exported')
                        ->withChanges(['attributes' => ['tabellen' => $tables]])
                        ->log('context_exported');

                    return response()->streamDownload(static function () use ($path): void {
                        readfile($path);
                        unlink($path);
                    }, 'saft-plugin-context_'.now()->format('Y-m-d_Hi').'.zip', ['Content-Type' => 'application/zip']);
                }),
            Action::make('cleanup')
                ->label(__('plugins.orphans.action'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => self::canManage() && app(PluginCleanup::class)->hasOrphans())
                ->modalDescription(__('plugins.orphans.help'))
                ->modalSubmitActionLabel(__('plugins.orphans.submit'))
                ->schema(fn (): array => [
                    // Fehlen Plugins nur vorübergehend (z. B. Update mit „composer install --no-dev“), kostet Aufräumen
                    // die Zuweisung der Rechte an Rollen: erst neu installieren.
                    Callout::make(__('plugins.orphans.reinstall_first'))
                        ->description(__('plugins.orphans.reinstall_first_help'))
                        ->warning(),
                    CheckboxList::make('menus')
                        ->label(__('plugins.orphans.menus'))
                        ->options($menus = app(PluginCleanup::class)->orphanedMenus())
                        ->default(array_keys($menus))
                        ->visible($menus !== []),
                    CheckboxList::make('permissions')
                        ->label(__('plugins.orphans.permissions'))
                        ->helperText(__('plugins.orphans.permissions_help'))
                        ->options($permissions = app(PluginCleanup::class)->orphanedPermissions())
                        ->descriptions(self::permissionRoles())
                        ->visible($permissions !== []),
                ])
                ->action(function (array $data): void {
                    abort_unless(self::canManage(), 403);
                    $removed = app(PluginCleanup::class)->remove(
                        array_map(intval(...), (array) ($data['menus'] ?? [])),
                        array_map(intval(...), (array) ($data['permissions'] ?? [])),
                    );
                    Notification::make()->success()->title(__('plugins.orphans.removed', $removed))->send();
                    $this->redirect(static::getUrl(), navigate: true);
                }),
        ];
    }

    /** Vollständige Aufstellung der Abhängigkeiten eines Plugins (Knopf in der Spalte „Abhängigkeiten“). */
    public function dependenciesAction(): Action
    {
        $plugin = fn (array $arguments): ?SaftPlugin => app(PluginRegistry::class)->has((string) ($arguments['plugin'] ?? ''))
            ? app(PluginRegistry::class)->get((string) $arguments['plugin']) : null;

        return Action::make('dependencies')
            ->modalHeading(fn (array $arguments): string => __('plugins.dependencies.heading', ['plugin' => $plugin($arguments)?->label() ?? '']))
            ->modalContent(fn (array $arguments) => view('filament.plugins.dependencies', [
                'tables' => ($p = $plugin($arguments)) === null ? [] : app(PluginDependencies::class)->details($p),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('plugins.dependencies.close'));
    }

    /** Angabe des Plugins, z. B. „API ^1.0“ oder „API ^1.0 · SAFT ^2.6“. */
    public static function requirement(SaftPlugin $plugin): string
    {
        return implode(' · ', array_filter([
            $plugin->requiresApi() === null ? null : 'API '.$plugin->requiresApi(),
            $plugin->requires() === null ? null : 'SAFT '.$plugin->requires(),
        ]));
    }

    /** Was diese Installation bereitstellt, z. B. „API 1.0 · SAFT 2.6.0“. */
    public static function provided(): string
    {
        return 'API '.implode(', ', ApiManifest::versions()).' · SAFT '.config('saft.version');
    }

    /**
     * Tabellen des Table-Designers für den Entwickler-Kontext: Menügruppe => [Name => „Anzeigename (name)“], beides
     * alphabetisch; ohne Menüeintrag unter „Ohne Menüeintrag“.
     *
     * @return array<string, array<string, string>>
     */
    private static function contextTableGroups(): array
    {
        $paths = DataMenu::groupPaths();
        $groups = [];

        foreach (CustomTable::query()->designer()->get()->sortBy(static fn (CustomTable $t): string => mb_strtolower((string) $t->label)) as $table) {
            $groups[$paths[$table->name] ?? __('roles.matrix.no_menu')][$table->name] = $table->label.' ('.$table->name.')';
        }

        uksort($groups, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $groups;
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can('plugins.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => array_map(self::row(...), app(PluginRegistry::class)->all()))
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label(__('plugins.fields.name'))
                    ->weight('medium')
                    ->wrap()
                    // Beschreibung und Installationsort (Pfad der Plugin-Klasse, Composer-Paket).
                    ->description(fn (array $record): HtmlString => new HtmlString(
                        e($record['description']).'<br><span class="font-mono text-xs">'.e($record['location']).'</span>'
                        .'<br><span class="text-xs">'.e(__('plugins.package', ['package' => $record['package']])).'</span>',
                    )),
                TextColumn::make('status')
                    ->label(__('plugins.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('plugins.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        PluginStates::ACTIVE => 'success', PluginStates::RESTING => 'warning', default => 'gray'
                    }),
                TextColumn::make('id')->label(__('plugins.fields.id'))->fontFamily('mono'),
                TextColumn::make('version')->label(__('plugins.fields.version'))->placeholder('–'),
                TextColumn::make('compatibility')
                    ->label(__('plugins.fields.compatibility'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('plugins.compatibility.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'yes' => 'success', 'no' => 'danger', default => 'gray'
                    })
                    ->description(fn (array $record): string => $record['requires'])
                    ->tooltip(fn (array $record): ?string => $record['compatibility'] === 'no'
                        ? __('plugins.incompatible', ['requires' => $record['requires'], 'provided' => self::provided()]) : null),
                // Zustand und daneben der Knopf für die vollständige Aufstellung (Aktion „dependencies“ der Seite).
                ViewColumn::make('dependencies')
                    ->label(__('plugins.fields.dependencies'))
                    ->view('filament.plugins.dependencies-column'),
                TextColumn::make('permissions')
                    ->label(__('plugins.fields.permissions'))
                    ->listWithLineBreaks()
                    ->wrap()
                    ->placeholder(__('plugins.no_permissions')),
                TextColumn::make('hooks')
                    ->label(__('plugins.fields.hooks'))
                    ->listWithLineBreaks()
                    ->wrap()
                    ->placeholder(__('plugins.api.none')),
                TextColumn::make('menus')->label(__('plugins.fields.menus')),
            ])
            ->recordActions(RowIcons::of([
                Action::make('disablePlugin')
                    ->label(__('plugins.switch.disable'))
                    // Das Symbol zeigt den Zustand (grün = aktiv), der Tooltip, was ein Klick bewirkt; der Dialog bleibt rot.
                    ->icon('heroicon-o-power')
                    ->color('success')
                    ->modalIconColor('danger')
                    ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
                    ->visible(fn (array $record): bool => $record['enabled'] && self::canManage())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => __('plugins.switch.disable_heading', ['plugin' => $record['label']]))
                    ->modalDescription(fn (array $record): HtmlString => self::disableEffects(app(PluginRegistry::class)->get($record['id'])))
                    ->modalSubmitActionLabel(__('plugins.switch.disable'))
                    ->action(function (array $record): void {
                        abort_unless(self::canManage(), 403);
                        app(PluginStates::class)->disable($record['id']);
                        Notification::make()->success()->title(__('plugins.switch.disabled', ['plugin' => $record['label']]))->send();
                        // Menü und Dashboard ohne das Plugin.
                        $this->redirect(static::getUrl(), navigate: true);
                    }),
                Action::make('enablePlugin')
                    ->label(__('plugins.switch.enable'))
                    ->icon('heroicon-o-power')
                    ->color('danger')
                    ->modalIconColor('success')
                    ->modalSubmitAction(fn (Action $action): Action => $action->color('success'))
                    ->visible(fn (array $record): bool => ! $record['enabled'] && self::canManage())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => __('plugins.switch.enable_heading', ['plugin' => $record['label']]))
                    ->modalDescription(__('plugins.switch.enable_help'))
                    ->modalSubmitActionLabel(__('plugins.switch.enable'))
                    ->action(function (array $record): void {
                        abort_unless(self::canManage(), 403);
                        app(PluginStates::class)->enable($record['id']);
                        Notification::make()->success()->title(__('plugins.switch.enabled', ['plugin' => $record['label']]))->send();
                        $this->redirect(static::getUrl(), navigate: true);
                    }),
                Action::make('adoptMenus')
                    ->label(__('plugins.actions.adopt'))
                    ->icon('heroicon-o-bars-3-bottom-left')
                    ->visible(fn (array $record): bool => $record['missing'] > 0 && self::canManage())
                    ->requiresConfirmation()
                    ->modalDescription(__('plugins.actions.adopt_help'))
                    ->action(function (array $record): void {
                        abort_unless(self::canManage(), 403);
                        $count = app(PluginMenus::class)->adopt(app(PluginRegistry::class)->get($record['id']));
                        Notification::make()->success()->title(__('plugins.actions.adopted', ['count' => $count]))->send();
                        // Neue Einträge sofort in der Seitenleiste.
                        $this->redirect(static::getUrl(), navigate: true);
                    }),
            ]))
            ->emptyStateHeading(__('plugins.empty'))
            ->emptyStateDescription(__('plugins.empty_help'))
            ->emptyStateIcon('heroicon-o-puzzle-piece');
    }

    /**
     * An welchen Tabellen das Plugin mithört und wo es Aktionen einblendet (Bezeichnungen der Tabellen).
     *
     * @return list<string>
     */
    private static function hooks(SaftPlugin $plugin): array
    {
        $labels = CustomTable::labelOptions();
        $names = static fn (array $tables): string => implode(', ', array_map(static fn (string $t): string => $labels[$t] ?? $t, array_values(array_unique($tables))));
        $hooks = [];

        if (($listens = array_map(static fn (RecordListener $l): string => $l->table, $plugin->recordListeners())) !== []) {
            $hooks[] = __('plugins.listens', ['tables' => $names($listens)]);
        }

        if (($actions = array_map(static fn (DataAction $a): string => $a->table, $plugin->dataActions())) !== []) {
            $hooks[] = __('plugins.data_actions', ['tables' => $names($actions)]);
        }

        if (($widgets = app(PluginInspector::class)->widgets($plugin)) !== []) {
            $hooks[] = __('plugins.widgets', ['widgets' => implode(', ', array_map(
                static fn (string $w): string => is_subclass_of($w, DashboardWidget::class) ? $w::dashboardLabel() : class_basename($w),
                $widgets,
            ))]);
        }

        if (($functions = array_map(static fn (FormulaFunction $f): string => $f->name.'()', $plugin->formulaFunctions())) !== []) {
            $hooks[] = __('plugins.formula_functions', ['functions' => implode(', ', $functions)]);
        }

        if (($rules = array_map(static fn (FieldRule $r): string => $r->label, $plugin->fieldRules())) !== []) {
            $hooks[] = __('plugins.field_rules', ['rules' => implode(', ', $rules)]);
        }

        return $hooks;
    }

    /** Was beim Ausschalten wegfällt – für den Bestätigungsdialog. */
    public static function disableEffects(SaftPlugin $plugin): HtmlString
    {
        $states = app(PluginStates::class);
        $items = [__('plugins.switch.effect_pages'), __('plugins.switch.effect_extensions'), __('plugins.switch.effect_keep')];
        $html = '<p>'.e(__('plugins.switch.disable_intro')).'</p><ul style="list-style: disc; padding-inline-start: 1.25rem; text-align: start">';

        foreach ($items as $item) {
            $html .= '<li>'.e($item).'</li>';
        }

        $html .= '</ul>';

        if (($dependents = $states->dependents($plugin)) !== []) {
            $html .= '<p style="margin-top: .5rem">'.e(__('plugins.switch.dependents', ['plugins' => app(PluginDependencies::class)->names($dependents)])).'</p>';
        }

        if ($plugin->recordListeners() !== []) {
            $html .= '<p style="margin-top: .5rem; font-weight: 600">'.e(__('plugins.switch.listeners_warning', [
                'tables' => implode(', ', array_values(array_unique(array_map(
                    static fn (RecordListener $l): string => CustomTable::labelOptions()[$l->table] ?? $l->table,
                    $plugin->recordListeners(),
                )))),
            ])).'</p>';
        }

        return new HtmlString($html);
    }

    /** @return array<string, mixed> */
    private static function row(SaftPlugin $plugin): array
    {
        $total = count($plugin->menuSuggestions());
        $missing = count(app(PluginMenus::class)->missing($plugin));
        $states = app(PluginStates::class);
        $requires = $states->requires($plugin);

        return [
            'id' => $plugin->id(),
            'label' => $plugin->label(),
            'description' => $plugin->description(),
            'version' => $plugin->version(),
            'requires' => self::requirement($plugin),
            'compatibility' => match ($plugin->isCompatible()) {
                true => 'yes', false => 'no', null => 'unknown'
            },
            'location' => $plugin->location(),
            'package' => $plugin->packageName(),
            'status' => $states->status($plugin),
            'enabled' => $states->isEnabled($plugin->id()),
            'satisfied' => ($problems = $plugin->missingDependencies()) === [],
            'required' => $plugin->requiredTables() !== [],
            'dependencies' => $problems !== [] ? $problems : array_values(array_filter([
                $plugin->requiredTables() === [] ? ($requires === [] ? __('plugins.dependencies.none') : null)
                    : __('plugins.dependencies.ok', ['count' => count($plugin->requiredTables())]),
                $requires === [] ? null : __('plugins.dependencies.requires_plugins', ['plugins' => app(PluginDependencies::class)->names($requires)]),
            ])),
            'permissions' => array_values($plugin->permissions()),
            'hooks' => self::hooks($plugin),
            'menus' => $total === 0 ? __('plugins.no_menus') : __('plugins.menus_state', ['present' => $total - $missing, 'total' => $total]),
            'missing' => $missing,
        ];
    }
}
