<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Configuration\ConfigExporter;
use App\Configuration\ConfigImporter;
use App\Configuration\ImportItem;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administration › Konfiguration übertragen: Tabellen, Auswertungen, Menüs und Rollen als Paket exportieren und in einer
 * anderen Installation einspielen (nur Neues; Vorschau zuerst).
 */
class ConfigurationTransferPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const string ROUTE = 'filament.admin.pages.configuration-transfer';

    protected static ?string $slug = 'configuration-transfer';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $exportData = [];

    /** @var array<string, mixed>|null */
    public ?array $importData = [];

    /** Gespeichertes Paket der Vorschau (storage/app/private/configuration/…). */
    public ?string $packageFile = null;

    /** @var list<array<string, string>> Zeilen der Vorschau */
    public array $items = [];

    /** @var array<string, string> Kopfdaten des Pakets */
    public array $packageInfo = [];

    /** @var list<string> */
    public array $requirements = [];

    /** Seite und Menüeintrag mit „anzeigen“; die Reiter je nach „exportieren“ bzw. „einspielen“. */
    public static function canAccess(): bool
    {
        return (auth()->user()?->can('configuration.view') ?? false) && (self::canExport() || self::canImport());
    }

    private static function canExport(): bool
    {
        return auth()->user()?->can('configuration.export') ?? false;
    }

    private static function canImport(): bool
    {
        return auth()->user()?->can('configuration.import') ?? false;
    }

    public function getTitle(): string
    {
        return __('configuration.title');
    }

    public function mount(): void
    {
        $this->exportForm->fill();
        $this->importForm->fill();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('transfer')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('export')
                        ->label(__('configuration.tabs.export'))
                        ->icon('heroicon-o-arrow-up-tray')
                        ->visible(self::canExport())
                        ->schema([EmbeddedSchema::make('exportForm')]),
                    Tab::make('import')
                        ->label(__('configuration.tabs.import'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(self::canImport())
                        ->schema([
                            EmbeddedSchema::make('importForm'),
                            Section::make(__('configuration.import.preview'))
                                ->description(fn (): string => $this->packageInfo === [] ? '' : __('configuration.import.package', $this->packageInfo))
                                ->visible(fn (): bool => $this->packageFile !== null)
                                ->schema([
                                    Callout::make(__('configuration.import.requirements'))
                                        ->description(fn (): string => implode(' · ', $this->requirements))
                                        ->warning()
                                        ->visible(fn (): bool => $this->requirements !== []),
                                    EmbeddedTable::make(),
                                    Actions::make([$this->applyPackageAction(), $this->resetPackageAction()]),
                                ]),
                        ]),
                ]),
        ]);
    }

    public function exportForm(Schema $schema): Schema
    {
        $user = auth()->user();

        return $schema
            ->statePath('exportData')
            ->components([
                Text::make(__('configuration.export.intro')),
                // Vier Reiter statt einer langen Seite; die Zahl am Reiter nennt die gewählten Einträge.
                Tabs::make('exportParts')->tabs([
                    Tab::make('exportTables')
                        ->label(__('configuration.export.tables'))
                        ->badge(fn (Get $get): ?string => self::selectedCount($get('tables')))
                        ->schema(array_map(static fn (string $group, array $tables): Fieldset => Fieldset::make($group)
                            ->schema([CheckboxList::make('tables.'.md5($group))->hiddenLabel()->options($tables)->bulkToggleable()->columns(2)->live()])
                            ->columns(1), array_keys($groups = self::tableGroups()), $groups)),
                    // Auswertungen nach der Menügruppe ihrer Grundtabelle, je mit den Tabellen, die sie braucht.
                    Tab::make('exportReports')
                        ->label(__('configuration.export.reports'))
                        ->badge(fn (Get $get): ?string => self::selectedCount($get('reports')))
                        ->schema(array_map(fn (string $group, array $reports): Fieldset => Fieldset::make($group)
                            ->schema([CheckboxList::make('reports.'.md5($group))->hiddenLabel()->bulkToggleable()->columns(2)->live()
                                ->options(array_map(static fn (array $r): string => $r['name'], $reports))
                                // Ohne „Abhängigkeiten mitnehmen“ ausgegraut, solange Tabellen der Auswertung nicht gewählt sind.
                                ->disableOptionWhen(fn (string $value, Get $get): bool => $this->reportAbsent((int) $value, ...$this->selectionTablesAndFlag($get)) !== [])
                                ->descriptions(fn (Get $get): array => array_map(fn (array $r): string => __('configuration.export.report_tables', ['tables' => $r['labels']])
                                    .(($absent = $this->reportAbsent($r['id'], ...$this->selectionTablesAndFlag($get))) === [] ? '' : ' – '.__('configuration.export.report_absent', ['tables' => implode(', ', $absent)])), $reports))])
                            ->columns(1), array_keys($reportGroups = $this->reportGroups()), $reportGroups)),
                    Tab::make('exportMenus')
                        ->label(__('configuration.export.menus'))
                        ->badge(fn (Get $get): ?string => self::selectedCount($get('menus')))
                        // In Boxen nach übergeordnetem Eintrag (wie Tabellen und Auswertungen); oberste Ebene zuerst.
                        ->schema(array_map(fn (string $group, array $entries): Fieldset => Fieldset::make($group)
                            ->schema([CheckboxList::make('menus.'.md5($group))->hiddenLabel()->bulkToggleable()->columns(2)->live()->options($entries)
                                // Ziel des Eintrags; ohne „Abhängigkeiten mitnehmen“ ausgegraut, solange Tabelle bzw. Auswertung fehlt.
                                ->disableOptionWhen(fn (string $value, Get $get): bool => $this->menuAbsent((int) $value, ...$this->selection($get)) !== [])
                                ->descriptions(fn (Get $get): array => array_filter(array_map(fn (array $t): string => $t['description']
                                    .(($absent = $this->menuAbsent($t['id'], ...$this->selection($get))) === [] ? '' : ' – '.__('configuration.export.report_absent', ['tables' => implode(', ', $absent)])),
                                    array_intersect_key($this->menuTargets(), $entries))))])
                            ->columns(1), array_keys($menuGroups = self::menuGroups()), $menuGroups)),
                    Tab::make('exportRoles')
                        ->label(__('configuration.export.roles'))
                        ->badge(fn (Get $get): ?string => self::selectedCount($get('roles')))
                        ->schema([CheckboxList::make('roles')->hiddenLabel()->bulkToggleable()->columns(3)->live()
                            ->options(Role::query()->orderBy('name')->pluck('name', 'id')->all())]),
                ]),
                Toggle::make('dependencies')->label(__('configuration.export.dependencies'))->helperText(__('configuration.export.dependencies_help'))->default(true)->live(),
                Callout::make(__('configuration.export.missing_tables_title'))
                    // Je Auswertung die fehlenden Tabellen. Nur Inline-HTML: die Beschreibung steht in einem <p>, eine Liste
                    // darin bringt Livewire beim Aktualisieren aus dem Tritt (die Meldung erschien dann nicht).
                    ->description(fn (Get $get): HtmlString => new HtmlString(e(__('configuration.export.missing_tables'))
                        .implode('', array_map(static fn (string $entry, array $absent): string => '<br>• <strong>'.e($entry).'</strong>: '.e(implode(', ', $absent)),
                            array_keys($missing = $this->blockedSelections($get)), $missing))))
                    ->warning()
                    ->visible(fn (Get $get): bool => ! $get('dependencies') && $this->blockedSelections($get) !== []),
                TextInput::make('label')->label(__('configuration.export.label'))->placeholder(__('configuration.export.label_placeholder'))->maxLength(120),
                Actions::make([
                    Action::make('exportPackage')
                        ->label(__('configuration.export.submit'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (): ?StreamedResponse => $this->exportPackage()),
                ]),
            ]);
    }

    public function importForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('importData')
            ->components([
                Text::make(__('configuration.import.intro')),
                FileUpload::make('file')
                    ->label(__('configuration.import.file'))
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(ConfigImporter::MAX_BYTES / 1024)
                    ->storeFiles(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->analyzeUpload())
                    ->visible(fn (): bool => $this->packageFile === null),
            ]);
    }

    public function exportPackage(): ?StreamedResponse
    {
        abort_unless(self::canExport(), 403);
        // Rohzustand statt getState(): ausgegraute, aber angehakte Auswertungen würden die Prüfung der Auswahl scheitern
        // lassen. Was exportiert werden darf, prüfen Exporter (freigegeben/eigene) und der Filter unten.
        $data = (array) $this->exportData;
        $tables = array_values(array_map('strval', array_merge([], ...array_values(array_map(static fn (mixed $g): array => (array) $g, (array) ($data['tables'] ?? []))))));
        $reports = array_map('intval', array_merge([], ...array_values(array_map(static fn (mixed $g): array => (array) $g, (array) ($data['reports'] ?? [])))));
        $menus = array_map('intval', array_merge([], ...array_values(array_map(static fn (mixed $g): array => (array) $g, (array) ($data['menus'] ?? [])))));
        $roles = array_map('intval', (array) ($data['roles'] ?? []));

        if ($tables === [] && $reports === [] && $menus === [] && $roles === []) {
            Notification::make()->warning()->title(__('configuration.export.empty'))->send();

            return null;
        }

        // Ohne Abhängigkeiten nur, was vollständig gewählt ist (das Übrige ist ausgegraut): Auswertungen mit allen
        // Tabellen, Menüeinträge mit ihrer Tabelle bzw. exportierbaren Auswertung.
        if (! ($data['dependencies'] ?? true)) {
            $reports = array_values(array_filter($reports, fn (int $id): bool => $this->reportAbsent($id, $tables) === []));
            $menus = array_values(array_filter($menus, fn (int $id): bool => $this->menuAbsent($id, $tables, $reports) === []));
        }

        $user = auth()->user();
        $package = app(ConfigExporter::class)->export($tables, $reports, $menus, $roles, (bool) ($data['dependencies'] ?? true), $user instanceof User ? $user : null, mb_substr(trim((string) ($data['label'] ?? '')), 0, 120));

        activity('configuration')
            ->event('config_exported')
            ->withChanges(['attributes' => [
                'paket' => $package['label'],
                'tabellen' => array_column($package['tables'], 'name'),
                'auswertungen' => array_column($package['reports'], 'name'),
                'menues' => array_column($package['menus'], 'path'),
                'rollen' => array_column($package['roles'], 'name'),
            ]])
            ->log('config_exported');

        $json = (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $name = 'saft-config_'.($package['label'] !== '' ? Str::slug($package['label'], '_').'_' : '').now()->format('Y-m-d_Hi').'.json';

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $name, ['Content-Type' => 'application/json']);
    }

    /** Hochgeladene Datei prüfen, für das Einspielen ablegen und die Vorschau füllen. */
    public function analyzeUpload(): void
    {
        abort_unless(self::canImport(), 403);
        $file = collect((array) ($this->importData['file'] ?? []))->first(static fn (mixed $f): bool => $f instanceof TemporaryUploadedFile);

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $package = app(ConfigImporter::class)->read((string) $file->getRealPath());
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->importData['file'] = null;

            return;
        }

        $directory = storage_path('app/private/configuration');
        File::ensureDirectoryExists($directory);
        $this->packageFile = $directory.'/'.Str::uuid().'.json';
        File::put($this->packageFile, (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @unlink((string) $file->getRealPath());
        $this->importData['file'] = null;
        $this->refreshPreview($package);
    }

    /** @param  array<string, mixed>  $package */
    private function refreshPreview(array $package): void
    {
        $importer = app(ConfigImporter::class);
        $this->items = array_map(static fn (ImportItem $i): array => $i->toRow(), $importer->analyze($package));
        $this->requirements = $importer->missingRequirements($package);
        $this->packageInfo = [
            'label' => (string) ($package['label'] ?? '') ?: '–',
            'source' => (string) ($package['source'] ?? '–'),
            'saft' => (string) ($package['saft'] ?? '?'),
            'date' => isset($package['created_at']) ? (string) Carbon::parse((string) $package['created_at'])->format('d.m.Y H:i') : '–',
        ];
        $this->resetTable();
    }

    /** Auswahl „Neue App“ beim Einspielen. */
    private const string NEW_APP = 'new';

    public function applyPackageAction(): Action
    {
        return Action::make('applyPackage')
            ->label(__('configuration.import.apply'))
            ->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('configuration.import.apply_confirm', ['count' => $this->newCount()]))
            ->disabled(fn (): bool => $this->newCount() === 0)
            ->tooltip(fn (): ?string => $this->newCount() === 0 ? __('configuration.import.nothing') : null)
            // Ziel-App (B-107): eine bestehende oder eine neue, vorgeschlagen mit der Bezeichnung des Pakets.
            ->schema([
                Select::make('app')
                    ->label(__('configuration.import.target_app'))
                    ->options(fn (): array => [self::NEW_APP => __('configuration.import.new_app'), ...App::query()->orderBy('sort_order')->get()->mapWithKeys(static fn (App $a): array => [(string) $a->id => (string) $a->name])->all()])
                    ->default(self::NEW_APP)
                    ->required()
                    ->live(),
                TextInput::make('new_app_name')
                    ->label(__('configuration.import.new_app_name'))
                    ->default(fn (): string => ($this->packageInfo['label'] ?? '–') !== '–' ? (string) $this->packageInfo['label'] : __('configuration.import.new_app_default'))
                    ->maxLength(120)
                    ->required(fn (Get $get): bool => $get('app') === self::NEW_APP)
                    ->visible(fn (Get $get): bool => $get('app') === self::NEW_APP),
            ])
            ->action(function (array $data): void {
                abort_unless(self::canImport() && $this->packageFile !== null && is_file($this->packageFile), 403);
                $package = app(ConfigImporter::class)->read($this->packageFile);
                $user = auth()->user();

                try {
                    $app = ($data['app'] ?? self::NEW_APP) === self::NEW_APP
                        ? App::query()->create(['name' => [app()->getLocale() => trim((string) ($data['new_app_name'] ?? '')) ?: __('configuration.import.new_app_default')]])
                        : (int) $data['app'];
                    $created = app(ConfigImporter::class)->apply($package, $user instanceof User ? $user : null, $app);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    $this->refreshPreview($package);

                    return;
                }

                Notification::make()->success()->title(__('configuration.import.applied', [
                    'tables' => $created[ImportItem::TABLE], 'links' => $created[ImportItem::REPORT_LINK], 'reports' => $created[ImportItem::REPORT],
                    'menus' => $created[ImportItem::MENU], 'roles' => $created[ImportItem::ROLE],
                ]))->persistent()->send();
                $this->refreshPreview($package);
            });
    }

    public function resetPackageAction(): Action
    {
        return Action::make('resetPackage')
            ->label(__('configuration.import.reset'))
            ->color('gray')
            ->action(function (): void {
                if ($this->packageFile !== null) {
                    @unlink($this->packageFile);
                }

                $this->packageFile = null;
                $this->items = $this->requirements = $this->packageInfo = [];
                $this->importForm->fill();
            });
    }

    private function newCount(): int
    {
        return count(array_filter($this->items, static fn (array $i): bool => $i['status'] === ImportItem::NEW));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters): array {
                $status = $filters['status']['value'] ?? null;

                return collect($this->items)
                    ->filter(static fn (array $i): bool => $status === null || $status === '' || $i['status'] === $status)
                    ->keyBy('key')
                    ->all();
            })
            ->columns([
                TextColumn::make('area')->label(__('configuration.columns.area'))->badge()->color('gray')
                    ->formatStateUsing(static fn (string $state): string => __('configuration.areas.'.$state)),
                TextColumn::make('name')->label(__('configuration.columns.name'))->wrap(),
                TextColumn::make('status')->label(__('configuration.columns.status'))->badge()
                    ->formatStateUsing(static fn (string $state): string => __('configuration.status.'.$state))
                    ->color(static fn (string $state): string => match ($state) {
                        ImportItem::NEW => 'success',
                        ImportItem::IDENTICAL => 'gray',
                        ImportItem::DIFFERENT => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('notes')->label(__('configuration.columns.notes'))->wrap()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('configuration.columns.status'))
                    ->options(array_combine($s = [ImportItem::NEW, ImportItem::IDENTICAL, ImportItem::DIFFERENT, ImportItem::MISSING], array_map(static fn (string $x): string => __('configuration.status.'.$x), $s))),
            ])
            ->paginated(false);
    }

    /**
     * Tabellen nach Menügruppe (wie Rollen und Entwickler-Kontext), Gruppen und Tabellen alphabetisch.
     *
     * @return array<string, array<string, string>>
     */
    private static function tableGroups(): array
    {
        $paths = self::groupPaths();
        $groups = [];

        foreach (CustomTable::query()->designer()->get()->sortBy(static fn (CustomTable $t): string => mb_strtolower((string) $t->label)) as $table) {
            $groups[$paths[$table->name] ?? __('roles.matrix.no_menu')][$table->name] = $table->label.' ('.$table->name.')';
        }

        uksort($groups, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $groups;
    }

    /**
     * Menügruppe je Tabelle mit vorangestellter App (B-107): gleich benannte Gruppen verschiedener Apps (z. B. zweimal
     * „Daten“) bleiben getrennt.
     *
     * @return array<string, string> Tabellenname => „App › Gruppe“
     */
    private static function groupPaths(): array
    {
        $apps = CustomTable::query()->with('app')->get()->mapWithKeys(static fn (CustomTable $t): array => [$t->name => (string) ($t->app?->name ?? '')])->all();
        $groups = DataMenu::groupPaths();
        $paths = [];

        foreach ($apps as $table => $app) {
            $group = $groups[$table] ?? __('roles.matrix.no_menu');
            // Beginnt der Pfad schon mit dem Namen der App (z. B. „Lager (Demo) › Stammdaten“), nicht doppelt.
            $paths[$table] = $app === '' || str_starts_with($group, $app) ? $group : $app.' › '.$group;
        }

        return $paths;
    }

    /**
     * Exportierbare Auswertungen nach der Menügruppe ihrer Grundtabelle, je mit den Bezeichnungen der verwendeten
     * Tabellen (Grundtabelle zuerst); Gruppen und Auswertungen alphabetisch.
     *
     * @return array<string, array<int, array{name: string, tables: list<string>, labels: string}>>
     */
    private function reportGroups(): array
    {
        return once(fn (): array => $this->loadReportGroups());
    }

    /** @return array<string, array<int, array{id: int, name: string, tables: list<string>, labels: string}>> */
    private function loadReportGroups(): array
    {
        $user = auth()->user();
        $paths = self::groupPaths();
        $labels = CustomTable::query()->get()->mapWithKeys(static fn (CustomTable $t): array => [$t->name => (string) $t->label])->all();
        $groups = [];

        foreach (ConfigExporter::exportableReports($user instanceof User ? $user : null)->orderBy('name')->get() as $report) {
            $tables = app(ConfigExporter::class)->reportUses($report)['tables'];
            $base = $tables[0] ?? '';
            $groups[$paths[$base] ?? __('roles.matrix.no_menu')][(int) $report->id] = [
                'id' => (int) $report->id,
                'name' => (string) $report->name,
                'tables' => $tables,
                'labels' => implode(', ', array_map(static fn (string $t): string => $labels[$t] ?? $t, $tables)),
            ];
        }

        uksort($groups, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $groups;
    }

    /**
     * Auswahl aus dem Formular: gewählte Tabellen, gewählte Auswertungen und ob Abhängigkeiten mitkommen.
     *
     * @return array{0: list<string>, 1: list<int>, 2: bool}
     */
    private function selection(Get $get): array
    {
        $flatten = static fn (mixed $groups): array => array_merge([], ...array_values(array_map(static fn (mixed $g): array => (array) $g, (array) $groups)));

        return [array_map('strval', $flatten($get('tables'))), array_map('intval', $flatten($get('reports'))), (bool) ($get('dependencies') ?? true)];
    }

    /** @return array<string, string> technischer Name => Bezeichnung */
    private function tableLabels(): array
    {
        return once(static fn (): array => CustomTable::query()->get()->mapWithKeys(static fn (CustomTable $t): array => [$t->name => (string) $t->label])->all());
    }

    /**
     * Tabellen einer Auswertung, die nicht gewählt sind (Bezeichnungen); leer mit „Abhängigkeiten mitnehmen“.
     *
     * @param  list<string>  $tables  gewählte Tabellen
     * @return list<string>
     */
    private function reportAbsent(int $id, array $tables, bool $dependencies = false): array
    {
        if ($dependencies) {
            return [];
        }

        foreach ($this->reportGroups() as $group) {
            if (isset($group[$id])) {
                return array_values(array_map(fn (string $t): string => $this->tableLabels()[$t] ?? $t, array_diff($group[$id]['tables'], $tables)));
            }
        }

        return [];
    }

    /**
     * Was einem Menüeintrag fehlt: Tabelle der Datenansicht bzw. die Auswertung (gewählt und mit allen Tabellen);
     * Gruppen und andere Seiten haben keine Abhängigkeiten. Leer mit „Abhängigkeiten mitnehmen“.
     *
     * @param  list<string>  $tables
     * @param  list<int>  $reports
     * @return list<string>
     */
    private function menuAbsent(int $id, array $tables, array $reports, bool $dependencies = false): array
    {
        $target = $this->menuTargets()[$id] ?? null;

        if ($dependencies || $target === null) {
            return [];
        }

        return match ($target['kind']) {
            'data' => in_array($target['table'], $tables, true) ? [] : [$this->tableLabels()[$target['table']] ?? $target['table']],
            'report' => ! in_array($target['report'], $reports, true)
                ? [__('configuration.export.absent_report', ['report' => $target['name']])]
                : $this->reportAbsent($target['report'], $tables),
            default => [],
        };
    }

    /**
     * Ziele der Menüeinträge: Datenansicht (Tabelle) oder Auswertung, mit Beschreibung für die Auswahl.
     *
     * @return array<int, array{id: int, kind: string, table: string, report: int, name: string, description: string}>
     */
    private function menuTargets(): array
    {
        return once(function (): array {
            $reports = Report::query()->pluck('name', 'id')->all();
            $targets = [];

            foreach (Menu::query()->whereIn('route', [DataMenu::ROUTE, ReportResource::VIEW_ROUTE])->get() as $menu) {
                $parameters = (array) ($menu->route_parameters ?? []);
                $data = $menu->route === DataMenu::ROUTE;
                $table = (string) ($parameters['table'] ?? '');
                $report = (int) ($parameters['record'] ?? 0);
                $targets[(int) $menu->id] = [
                    'id' => (int) $menu->id,
                    'kind' => $data ? 'data' : 'report',
                    'table' => $table,
                    'report' => $report,
                    'name' => (string) ($reports[$report] ?? '#'.$report),
                    'description' => $data
                        ? __('configuration.export.menu_data', ['table' => $this->tableLabels()[$table] ?? $table])
                        : __('configuration.export.menu_report', ['report' => (string) ($reports[$report] ?? '#'.$report)]),
                ];
            }

            return $targets;
        });
    }

    /**
     * Ohne „Abhängigkeiten mitnehmen“: gewählte Auswertungen und Menüeinträge, die nicht exportiert werden, mit dem,
     * was ihnen fehlt.
     *
     * @return array<string, list<string>> Name bzw. Menüpfad => Fehlendes
     */
    private function blockedSelections(Get $get): array
    {
        [$tables, $reports, $dependencies] = $this->selection($get);

        if ($dependencies) {
            return [];
        }

        $blocked = [];
        $names = Report::query()->whereIn('id', $reports)->pluck('name', 'id')->all();

        foreach ($reports as $id) {
            if (($absent = $this->reportAbsent($id, $tables)) !== []) {
                $blocked[(string) ($names[$id] ?? '#'.$id)] = $absent;
            }
        }

        $paths = self::menuOptions();
        $effective = array_values(array_filter($reports, fn (int $id): bool => $this->reportAbsent($id, $tables) === []));

        foreach (array_map('intval', array_merge([], ...array_values(array_map(static fn (mixed $g): array => (array) $g, (array) $get('menus'))))) as $id) {
            if (($absent = $this->menuAbsent($id, $tables, $effective)) !== []) {
                $blocked[$paths[$id] ?? '#'.$id] = $absent;
            }
        }

        ksort($blocked, SORT_NATURAL | SORT_FLAG_CASE);

        return $blocked;
    }

    /** @return array{0: list<string>, 1: bool} gewählte Tabellen und „Abhängigkeiten mitnehmen“ */
    private function selectionTablesAndFlag(Get $get): array
    {
        [$tables, , $dependencies] = $this->selection($get);

        return [$tables, $dependencies];
    }

    /** Anzahl gewählter Einträge (auch über Gruppen verteilt) für die Zahl am Reiter; leer bei keiner Auswahl. */
    private static function selectedCount(mixed $state): ?string
    {
        $count = 0;

        foreach ((array) $state as $value) {
            $count += is_array($value) ? count($value) : 1;
        }

        return $count === 0 ? null : (string) $count;
    }

    /** @return array<int, string> Menüeinträge als Pfad, alphabetisch */
    /**
     * Menüeinträge nach übergeordnetem Eintrag (Pfad): Gruppe => [ID => Bezeichnung]; oberste Ebene zuerst, sonst
     * Gruppen und Einträge alphabetisch.
     *
     * @return array<string, array<int, string>>
     */
    private static function menuGroups(): array
    {
        $paths = self::menuOptions();
        $menus = Menu::query()->get(['id', 'parent_id', 'label'])->keyBy('id');
        $top = __('configuration.export.menu_top');
        $groups = [];

        foreach ($menus as $menu) {
            $group = $menu->parent_id === null ? $top : ($paths[(int) $menu->parent_id] ?? $top);
            $groups[$group][(int) $menu->id] = (string) $menu->label;
        }

        foreach ($groups as &$entries) {
            asort($entries, SORT_NATURAL | SORT_FLAG_CASE);
        }

        unset($entries);
        uksort($groups, static fn (string $a, string $b): int => [$a !== $top, mb_strtolower($a)] <=> [$b !== $top, mb_strtolower($b)]);

        return $groups;
    }

    private static function menuOptions(): array
    {
        $menus = Menu::query()->get()->keyBy('id');
        $options = [];

        foreach ($menus as $menu) {
            $labels = [];

            for ($current = $menu, $depth = 0; $current !== null && $depth < 10; $current = $menus->get((int) $current->parent_id), $depth++) {
                array_unshift($labels, (string) $current->label);
            }

            $options[(int) $menu->id] = implode(' › ', $labels);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
