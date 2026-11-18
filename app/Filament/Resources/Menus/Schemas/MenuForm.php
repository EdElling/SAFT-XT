<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Schemas;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Forms\Components\IconPicker;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\AppSelect;
use App\Filament\Support\TranslatableInputs;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Navigation\MenuTree;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class MenuForm
{
    public const string TYPE_GROUP = 'group';

    public const string TYPE_ROUTE = 'route';

    public const string TYPE_URL = 'url';

    /** Tabelle des Table-Designers (Datenseite mit eigener Spaltenauswahl). */
    public const string TYPE_DATA = 'data';

    /** Grafische Übersicht der Datentabellen der Gruppe, in der der Eintrag steht. */
    public const string TYPE_DIAGRAM = 'diagram';

    /** Gespeicherte, geteilte Auswertung (Report-Engine). */
    public const string TYPE_REPORT = 'report';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('menus.form.entry'))
                    ->columns(2)
                    ->schema([
                        TranslatableInputs::make('label', __('common.label'), required: true),
                        Select::make('parent_id')
                            ->label(__('menus.parent'))
                            ->placeholder(__('menus.root'))
                            ->options(fn (?Menu $record): array => MenuTree::fromDatabase()->parentOptions($record?->id))
                            ->searchable()
                            ->rule(static fn (?Menu $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $parentId = filled($value) ? (int) $value : null;

                                if (! MenuTree::fromDatabase()->canAttach($record?->id, $parentId)) {
                                    $fail(__('menus.form.invalid_position', ['max' => MenuTree::MAX_DEPTH]));
                                }
                            }),
                        // App (B-107) nur für oberste Einträge; darunter gilt die App der Gruppe bzw. der Tabelle.
                        AppSelect::make(required: false)
                            ->placeholder(__('apps.system'))
                            ->helperText(__('menus.form.app_help'))
                            ->visible(fn (Get $get): bool => blank($get('parent_id'))),
                        ToggleButtons::make('type')
                            ->label(__('common.type'))
                            ->options([
                                self::TYPE_GROUP => __('menus.types.group'),
                                self::TYPE_ROUTE => __('menus.types.route'),
                                self::TYPE_URL => __('menus.types.url'),
                                self::TYPE_DATA => __('menus.types.data'),
                                self::TYPE_DIAGRAM => __('menus.types.diagram'),
                                self::TYPE_REPORT => __('menus.types.report'),
                            ])
                            ->helperText(fn (Get $get): ?string => $get('type') === self::TYPE_DIAGRAM ? __('menus.form.diagram_help') : null)
                            ->inline()
                            ->required()
                            ->live()
                            ->default(self::TYPE_ROUTE)
                            // Das Recht einer Datentabelle gehört zur Tabelle, nicht zum neuen Typ.
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old): void {
                                $tableRight = DataPermissions::name((string) $get('data_table'), 'view');

                                if ($old === self::TYPE_DATA && $state !== self::TYPE_DATA && $get('permission_name') === $tableRight) {
                                    $set('permission_name', null);
                                }
                            })
                            ->afterStateHydrated(function (ToggleButtons $component, ?Menu $record): void {
                                if ($record !== null) {
                                    $component->state(self::typeOf($record));
                                }
                            })
                            ->columnSpanFull(),
                        Select::make('route')
                            ->label(__('menus.form.route'))
                            ->options(fn (): array => self::routeOptions())
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => $get('type') === self::TYPE_ROUTE)
                            ->visible(fn (Get $get): bool => $get('type') === self::TYPE_ROUTE)
                            ->columnSpanFull(),
                        KeyValue::make('route_parameters')
                            ->label(__('menus.form.route_parameters'))
                            ->keyLabel(__('menus.form.parameter'))
                            ->valueLabel(__('menus.form.value'))
                            ->helperText(fn (Get $get): string => __('menus.form.required_parameters', ['parameters' => implode(', ', self::routeParameterNames((string) $get('route'))) ?: __('menus.form.none')]))
                            ->visible(fn (Get $get): bool => $get('type') === self::TYPE_ROUTE && self::routeParameterNames((string) $get('route')) !== [])
                            ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $missing = array_diff(self::routeParameterNames((string) $get('route')), array_keys(array_filter((array) $value, 'filled')));

                                if ($missing !== []) {
                                    $fail(__('menus.form.missing_parameters', ['parameters' => implode(', ', $missing)]));
                                }
                            })
                            ->columnSpanFull(),
                        Select::make('report')
                            ->label(__('menus.types.report'))
                            ->helperText(__('menus.form.report_help'))
                            ->options(fn (): array => Report::query()->where('is_shared', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('type') === self::TYPE_REPORT)
                            ->visible(fn (Get $get): bool => $get('type') === self::TYPE_REPORT)
                            ->afterStateHydrated(function (Select $component, ?Menu $record): void {
                                if ($record !== null && $record->route === ReportResource::VIEW_ROUTE) {
                                    $component->state($record->route_parameters['record'] ?? null);
                                }
                            })
                            ->columnSpanFull(),
                        Select::make('data_table')
                            ->label(__('menus.types.data'))
                            // Nur Tabellen der App der Gruppe bzw. der gewählten App (B-107).
                            ->options(fn (Get $get): array => CustomTable::labelOptions(activeOnly: true, appId: self::entryAppId($get)))
                            ->in(fn (Get $get): array => array_keys(CustomTable::labelOptions(activeOnly: true, appId: self::entryAppId($get))))
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => $get('type') === self::TYPE_DATA)
                            ->visible(fn (Get $get): bool => $get('type') === self::TYPE_DATA)
                            ->afterStateHydrated(function (Select $component, ?Menu $record): void {
                                if ($record !== null && $record->route === DataMenu::ROUTE) {
                                    $component->state($record->route_parameters['table'] ?? null);
                                }
                            })
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $set('data_columns', self::defaultColumns($state));

                                $field = 'label.'.config('locales.default');

                                if (blank($get($field)) && $state !== null) {
                                    $set($field, CustomTable::query()->where('name', $state)->first()?->getTranslation('label', (string) config('locales.default')));
                                }
                            })
                            ->helperText(__('menus.form.data_table_help'))
                            ->columnSpanFull(),
                        CheckboxList::make('data_columns')
                            ->label(__('menus.form.columns'))
                            // Pflichtfelder stehen nicht zur Wahl: sie werden immer angezeigt.
                            ->helperText(fn (Get $get): string => __('menus.form.columns_help', ['fields' => self::dataFields((string) $get('data_table'))->where('is_required', true)->pluck('label')->implode(', ') ?: '—']))
                            ->options(fn (Get $get): array => self::dataFields((string) $get('data_table'))
                                ->where('is_required', false)
                                ->mapWithKeys(fn (CustomField $f): array => [$f->id => $f->label])
                                ->all())
                            ->columns(3)
                            ->bulkToggleable()
                            ->visible(fn (Get $get): bool => $get('type') === self::TYPE_DATA && filled($get('data_table')))
                            ->afterStateHydrated(function (CheckboxList $component, ?Menu $record): void {
                                if ($record !== null && $record->route === DataMenu::ROUTE) {
                                    $table = $record->route_parameters['table'] ?? null;
                                    $optional = self::dataFields($table)->where('is_required', false)->pluck('id')->all();
                                    $selected = array_intersect(array_map('intval', (array) ($record->view_config['columns'] ?? self::defaultColumns($table))), $optional);
                                    $component->state(array_map('strval', array_values($selected)));
                                }
                            })
                            ->columnSpanFull(),
                        TextInput::make('url')
                            ->label(__('tables.fields.url'))
                            ->url()
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => $get('type') === self::TYPE_URL)
                            ->visible(fn (Get $get): bool => $get('type') === self::TYPE_URL)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('menus.form.appearance'))
                    ->columns(2)
                    ->schema([
                        IconPicker::make('icon')
                            ->placeholder('heroicon-o-users')
                            ->helperText(__('menus.form.icon_help')),
                        Select::make('permission_name')
                            ->label(__('menus.form.permission'))
                            ->placeholder(__('menus.form.permission_all'))
                            ->options(fn (): array => Permission::query()->orderBy('name')->pluck('name', 'name')->all())
                            // Datentabellen: automatisch data.<tabelle>.view.
                            ->visible(fn (Get $get): bool => $get('type') !== self::TYPE_DATA)
                            ->searchable(),
                        TextInput::make('sort_order')
                            ->label(__('common.sort_order'))
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('common.active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Bereinigt die Daten je nach Typ, damit ausgeblendete Felder keine Altwerte behalten.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * Gespeicherte Bezeichnungen als Formularwerte, je Sprache ein Feld.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /** App eines Eintrags im Formular: die seiner Gruppe, oben die gewählte; null = keine Einschränkung. */
    private static function entryAppId(Get $get): ?int
    {
        $parent = $get('parent_id');
        $appId = filled($parent) ? Menu::query()->whereKey((int) $parent)->value('app_id') : $get('app_id');

        return filled($appId) ? (int) $appId : null;
    }

    public static function fill(Menu $record, array $data): array
    {
        return [...$data, 'label' => $record->getTranslations('label')];
    }

    public static function normalize(array $data): array
    {
        $type = $data['type'] ?? self::TYPE_GROUP;
        $dataTable = $data['data_table'] ?? null;
        $dataColumns = (array) ($data['data_columns'] ?? []);
        $report = $data['report'] ?? null;
        unset($data['type'], $data['data_table'], $data['data_columns'], $data['report']);

        if ($type === self::TYPE_REPORT) {
            // Sichtbar mit „Auswertungen: anzeigen“; ob sie ausführbar ist, prüft der Menübaum.
            return [...$data, 'route' => ReportResource::VIEW_ROUTE, 'route_parameters' => ['record' => (string) $report], 'url' => null, 'view_config' => null, 'permission_name' => 'reports.view'];
        }

        if ($type === self::TYPE_DATA) {
            return [
                ...$data,
                'route' => DataMenu::ROUTE,
                'route_parameters' => ['table' => (string) $dataTable],
                'url' => null,
                // Sichtbarkeit im Menü folgt dem Leserecht auf die Tabelle.
                'permission_name' => DataPermissions::name((string) $dataTable, 'view'),
                // Details sind Eigenschaft der Tabelle (Table-Designer), nicht des Menüeintrags.
                'view_config' => ['columns' => self::withRequiredColumns((string) $dataTable, $dataColumns)],
            ];
        }

        $data['view_config'] = null;

        if ($type === self::TYPE_DIAGRAM) {
            // Parameter {menu} = eigene ID; bei neuen Einträgen setzt ihn das Model nach dem Speichern.
            return [...$data, 'route' => DataModelPage::ROUTE, 'route_parameters' => null, 'url' => null];
        }

        $data['route'] = $type === self::TYPE_ROUTE ? ($data['route'] ?? null) : null;
        $data['route_parameters'] = $type === self::TYPE_ROUTE && filled($data['route_parameters'] ?? null)
            ? array_intersect_key((array) $data['route_parameters'], array_flip(self::routeParameterNames((string) $data['route'])))
            : null;
        $data['url'] = $type === self::TYPE_URL ? ($data['url'] ?? null) : null;

        return $data;
    }

    public static function typeOf(Menu $menu): string
    {
        return match (true) {
            $menu->route === DataMenu::ROUTE => self::TYPE_DATA,
            $menu->route === DataModelPage::ROUTE => self::TYPE_DIAGRAM,
            $menu->route === ReportResource::VIEW_ROUTE => self::TYPE_REPORT,
            filled($menu->route) => self::TYPE_ROUTE,
            filled($menu->url) => self::TYPE_URL,
            default => self::TYPE_GROUP,
        };
    }

    /**
     * Aktive Felder einer Designer-Tabelle in Feldreihenfolge.
     *
     * @return Collection<int, CustomField>
     */
    public static function dataFields(?string $tableName): Collection
    {
        if (blank($tableName)) {
            return collect();
        }

        return once(fn () => CustomField::query()
            ->whereHas('custom_table', fn ($q) => $q->where('name', $tableName))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get());
    }

    /**
     * Vorgabe der wählbaren Spalten: Designer-Einstellung "In Tabelle anzeigen" (ohne
     * Pflichtfelder – diese werden ohnehin immer angezeigt).
     *
     * @return list<string>
     */
    public static function defaultColumns(?string $tableName): array
    {
        return self::dataFields($tableName)
            ->filter(fn (CustomField $f): bool => $f->show_in_table && ! $f->is_required)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Auswahl (Feld-IDs) bereinigt (nur existierende aktive Felder) und um Pflichtfelder
     * ergänzt, in Feldreihenfolge.
     *
     * @param  list<int|string>  $selected
     * @return list<int>
     */
    public static function withRequiredColumns(string $tableName, array $selected): array
    {
        $selected = array_map('intval', $selected);

        return self::dataFields($tableName)
            ->filter(fn (CustomField $f): bool => $f->is_required || in_array($f->id, $selected, true))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @return list<string> Platzhalter der Route, z. B. ['table']
     */
    public static function routeParameterNames(string $routeName): array
    {
        $route = $routeName === '' ? null : Route::getRoutes()->getByName($routeName);

        return $route === null ? [] : array_values($route->parameterNames());
    }

    /**
     * GET-Routen des Admin-Panels (ohne Auth-, Datensatz- und Livewire-Interna).
     *
     * @return array<string, string>
     */
    public static function routeOptions(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'filament.admin.')
                && in_array('GET', $route->methods(), true)
                // Datensatz-Seiten ({record}) sind kein sinnvolles Menüziel; Datentabellen und Datenmodell über eigenen Typ.
                && ! Str::contains($route->uri(), '{record}')
                && ! in_array($route->getName(), [DataMenu::ROUTE, DataModelPage::ROUTE], true)
                && ! Str::contains((string) $route->getName(), ['.auth.', '.logout']))
            ->mapWithKeys(fn (RoutingRoute $route): array => [(string) $route->getName() => "{$route->getName()}  (/{$route->uri()})"])
            ->sortKeys()
            ->all();
    }
}
