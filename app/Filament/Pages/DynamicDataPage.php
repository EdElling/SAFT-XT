<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Apps\CurrentApp;
use App\DynamicData\Cascade\CascadePath;
use App\DynamicData\Cascade\CascadeResolver;
use App\DynamicData\CustomTableLookup;
use App\DynamicData\DataAccess;
use App\DynamicData\DataView;
use App\DynamicData\DetailRelations;
use App\DynamicData\DetailSource;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\DynamicData\Ui\KeylessRow;
use App\DynamicData\Ui\RecordEntries;
use App\ExternalData\ExternalTableUnavailable;
use App\ExternalData\SchemaComparison;
use App\Filament\Concerns\HandlesStaleRecords;
use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Concerns\RemembersTableState;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\DataAction;
use App\Plugins\PluginRegistry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Datenpflege für Tabellen des Table-Designers mit Master-Detail-Kaskade:
 * /admin/data/{table}?view=<menü>&sel=<auswahlpfad>
 *
 * Klick auf eine Zeile wählt sie aus und blendet darunter ihre Details ein (Kurzanzeige
 * und konfigurierte Detailtabellen), bis zu CascadePath::MAX_LEVELS Ebenen. Alles ist
 * lesend; Bearbeiten, Verlauf und Löschen im Popup "Details".
 */
class DynamicDataPage extends Page implements HasTable
{
    use HandlesStaleRecords;
    use InteractsWithTable, RemembersTableColumns {
        getTableRecordKey as private filamentTableRecordKey;
        resolveTableRecord as private filamentResolveTableRecord;
        RemembersTableColumns::loadTableColumnsFromSession insteadof InteractsWithTable;
        RemembersTableColumns::persistTableColumns insteadof InteractsWithTable;
    }
    use RemembersTableState;

    protected static ?string $slug = 'data/{table}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $tableName = '';

    /** Menüeintrag mit Spaltenauswahl (?view=<menu-id>); nur Ansicht, keine Sicherheitsgrenze. */
    #[Url(as: 'view')]
    public ?int $viewId = null;

    /** Auswahlpfad der Kaskade (?sel=…), wird bei jeder Darstellung geprüft. */
    #[Url(as: 'sel')]
    public ?string $selection = null;

    /** Meldung, wenn die externe Datenbank einer angebundenen Tabelle nicht erreichbar ist. */
    #[Locked]
    public ?string $unavailable = null;

    private ?CustomTable $customTable = null;

    private ?DataView $dataView = null;

    /** Je Tabelle und Menüeintrag eigene Filter, Suche, Sortierung und Spaltenauswahl. */
    protected function rememberedTableIdentifier(): string
    {
        return 'data.'.$this->tableName.'.'.($this->viewId ?? 'default');
    }

    /** Routenname ohne Platzhalter: filament.admin.pages.data */
    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'data';
    }

    public static function urlFor(string $table, int|string|null $record = null, ?int $viewId = null): string
    {
        $selection = $record === null ? null : CascadePath::formatKey($record);

        return static::getUrl(array_filter(['table' => $table, 'view' => $viewId, 'sel' => $selection], fn ($v) => $v !== null));
    }

    public function mount(string $table): void
    {
        $this->tableName = $table;
        $customTable = $this->customTable();

        abort_unless($customTable->is_active, 404);
        abort_unless(DataAccess::current($customTable, 'view'), 403);
        // Link auf eine Tabelle einer anderen App: in diese App wechseln (B-107).
        app(CurrentApp::class)->follow(($user = auth()->user()) instanceof User ? $user : null, $customTable->app_id);
        $this->restoreRememberedTableState();

        if ($customTable->isExternal()) {
            $this->unavailable = $this->checkExternal($customTable);
        }
    }

    /** Verbindung einmal aufbauen: lieber eine klare Meldung als ein Fehler mitten in der Tabelle. */
    private function checkExternal(CustomTable $table): ?string
    {
        try {
            DynamicRecord::forTable($table)->getConnection()->getPdo();
            $difference = app(SchemaComparison::class)->compare($table);

            // Neue Spalten stören nicht; fehlende Spalten oder Tabellen würden die Abfrage scheitern lassen.
            return $difference->tableMissing || $difference->missing !== [] ? __('table_connector.schema.drift', ['table' => (string) $table->label]) : null;
        } catch (ExternalTableUnavailable $e) {
            return $e->getMessage();
        } catch (Throwable) {
            return __('table_connector.unreachable', ['connection' => (string) $table->databaseConnection?->name]);
        }
    }

    /** Auch bei jeder Livewire-Aktualisierung: entzogene Rechte/deaktivierte Tabelle greifen sofort. */
    public function hydrate(): void
    {
        $customTable = $this->customTable();

        abort_unless($customTable->is_active && DataAccess::current($customTable, 'view'), 403);
    }

    /** Klick auf eine Zeile der Liste (Filament ruft die Methode mit dem Schlüssel auf). */
    public function selectRecord(int|string $key): void
    {
        $customTable = $this->customTable();

        if (! $customTable->hasSingleKey()) {
            return;
        }

        $key = DynamicRecord::forTable($customTable)->getKeyType() === 'int' ? (int) $key : (string) $key;
        $this->selection = CascadePath::parse($this->selection)->selectRoot($key)->toString();
    }

    /** Klick auf eine Zeile einer Detailtabelle. */
    #[On('cascade-select')]
    public function selectDetail(int $level, int $fieldId, int|string $recordId): void
    {
        $this->selection = CascadePath::parse($this->selection)->select($level, $fieldId, $recordId)->toString();
    }

    #[On(DynamicRecordActions::REFRESH_EVENT)]
    public function refreshCascade(?string $deletedFrom = null, ?int $deletedRecord = null): void
    {
        // Darstellung baut die Kaskade neu auf. Gelöschte Datensätze bleiben für Benutzer mit
        // Löschrecht sichtbar, daher die Auswahl des gerade gelöschten ausdrücklich aufheben.
        ['path' => $path, 'levels' => $levels] = $this->resolvedPath();

        foreach ($levels as $level => $entry) {
            if ($entry['table']->name === $deletedFrom && (int) $entry['record']->getKey() === $deletedRecord) {
                $this->selection = ($level === 0 ? $path->truncate(0) : $path->withoutRecordAt($level))->toString();

                return;
            }
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->dataView()->label ?? $this->customTable()->label;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->customTable()->description;
    }

    /**
     * Für die Breitenregeln der Datenansicht (siehe AdminPanelProvider).
     *
     * @return array<string>
     */
    #[Unexposed]
    public function getPageClasses(): array
    {
        return ['saft-data-page'];
    }

    public function content(Schema $schema): Schema
    {
        if ($this->unavailable !== null) {
            return $schema->components([Callout::make(__('table_connector.unavailable_title'))->description($this->unavailable)->danger()]);
        }

        $cascade = $this->cascade();

        // Kurzanzeige der ersten Ebene gemeinsam mit der Tabelle darüber: gleiche Breite.
        return $schema->components([
            self::withRecord(EmbeddedTable::make(), array_shift($cascade)),
            ...$cascade,
        ]);
    }

    public function table(Table $table): Table
    {
        $customTable = $this->customTable();
        $builder = app(DynamicTableBuilder::class);
        $columns = $this->dataView()->columnNames($customTable);
        $selected = fn (): int|string|null => $this->resolvedPath()['path']->root;
        $external = $customTable->isExternal();
        $selectable = $customTable->hasSingleKey();
        $sort = $customTable->keyColumn() ?? $builder->tableFields($customTable, $columns)[0]->name ?? null;

        return $table
            ->identifier($this->rememberedTableIdentifier())
            ->query(fn () => $builder->query($customTable, $columns, toggleable: true))
            ->columns($builder->columns($customTable, $columns, toggleable: true))
            // Reihenfolge per Drag-and-Drop im Spalten-Menü, je Person gespeichert (RemembersTableColumns).
            ->reorderableColumns()
            ->filters([...$builder->filters($customTable), TrashedFilter::make()->visible(! $external && DataAccess::current($customTable, 'delete'))])
            ->filtersFormColumns(3)
            // Designer-Tabellen: neueste zuerst; externe Tabellen in Schlüsselreihenfolge.
            ->defaultSort($sort, $external ? 'asc' : 'desc')
            // Vorgabe 10 Zeilen; eine andere Wahl bleibt je Person gespeichert (RemembersTableState, B-027).
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->modelLabel(__('data.record'))
            ->pluralModelLabel($customTable->label)
            ->recordAction($selectable ? 'selectRecord' : null)
            ->recordClasses(fn (DynamicRecord $record): ?string => $selectable && (string) $record->getKey() === (string) $selected() ? 'saft-row-selected' : null)
            ->headerActions([
                // B-027: Filter, Suche, Sortierung und Zeilen pro Seite bleiben je Person gespeichert.
                $this->resetTableViewAction(),
                DynamicRecordActions::create($customTable),
                DynamicRecordActions::export($customTable, $columns),
                DynamicRecordActions::import($customTable),
                ...app(PluginRegistry::class)->dataActions($customTable, DataAction::TABLE),
            ])
            ->recordActions([
                // Zusammengesetzter Schlüssel: Details und Bearbeiten ja, Auswahl (Master-Detail) nein.
                ...DynamicRecordActions::rowActions($customTable, [], $customTable->hasKey() ? app(PluginRegistry::class)->dataActions($customTable, DataAction::RECORD) : []),
            ])
            // Auswahl mehrerer Datensätze (B-133 Teil 1): Häkchen erscheinen nur, wenn eine Sammelaktion sichtbar ist.
            ->toolbarActions([
                DynamicRecordActions::documentBulk($customTable),
            ])
            ->checkIfRecordIsSelectableUsing(static fn (DynamicRecord $record): bool => ! $record->trashed())
            ->emptyStateHeading(__('data.empty'));
    }

    /**
     * Zeilen ohne einzelnen Schlüssel (Views, Tabellen ohne Primärschlüssel) brauchen trotzdem
     * eine eindeutige Kennung für die Darstellung; sie sind weder auswählbar noch bearbeitbar.
     */
    public function getTableRecordKey(Model|array $record): string
    {
        $table = $this->customTable();

        if ($record instanceof Model && ! $table->hasSingleKey()) {
            return KeylessRow::key($table, $record);
        }

        return $this->filamentTableRecordKey($record);
    }

    /** Zeilen ohne einzelnen Schlüssel über die Werte in ihrer Kennung wiederfinden. */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        $table = $this->customTable();

        if ($key === null || $table->hasSingleKey()) {
            return $this->filamentResolveTableRecord($key);
        }

        $query = $this->applyFiltersToTableQuery($this->getTable()->getQuery(isResolvingRecord: true), isResolvingRecord: true);

        return KeylessRow::constrain($table, $query, $key)?->first();
    }

    /**
     * Kurzanzeige und Detailtabellen für jede ausgewählte Ebene.
     *
     * @return list<Component>
     */
    private function cascade(): array
    {
        ['path' => $path, 'levels' => $levels] = $this->resolvedPath();

        // Normalisierten Pfad zurückschreiben (ungültige Teile verschwinden aus der URL).
        $this->selection = $path->toString();

        if ($levels === []) {
            return [];
        }

        return $this->levelComponents($levels, $path, 0);
    }

    /**
     * @param  list<array{table: CustomTable, record: DynamicRecord, field: ?CustomField}>  $levels
     * @return list<Component>
     */
    private function levelComponents(array $levels, CascadePath $path, int $level): array
    {
        $current = $levels[$level] ?? null;

        if ($current === null) {
            return [];
        }

        $components = [
            Section::make(RecordEntries::title($current['table'], $current['record']))
                ->compact()
                // Oberste Ebene: dieselben Felder wie die sichtbaren Spalten der Liste (auch selbst eingeblendete).
                ->schema([RecordEntries::blocks($current['table'], $current['record'], compact: true, visible: $level === 0 ? $this->visibleColumnNames($current['table']) : null)]),
        ];

        if ($level + 1 >= CascadePath::MAX_LEVELS) {
            return $components;
        }

        $details = DetailRelations::configuredFor($current['table'])
            ->filter(fn (DetailSource $d): bool => DataAccess::current($d->child, 'view'))
            ->values();

        if ($details->isEmpty()) {
            return $components;
        }

        $activeField = $path->fieldAt($level + 1) ?? $details->first()->id;

        $panels = $details->map(fn (DetailSource $field): array => [
            'field' => $field,
            'schema' => [
                Livewire::make(DynamicDetailTable::class, [
                    'parentTable' => $current['table']->name,
                    'parentId' => $current['record']->getKey(),
                    'relationFieldId' => $field->id,
                    'level' => $level + 1,
                    'selectedId' => $field->id === $activeField ? $path->selectedAt($level + 1) : null,
                ])->key("cascade-{$level}-{$field->id}-{$current['record']->getKey()}"),
                // Nächste Ebene nur unter der Detailtabelle, in der ausgewählt wurde.
                ...($field->id === $activeField ? $this->levelComponents($levels, $path, $level + 1) : []),
            ],
        ])->map(fn (array $panel): array => [
            ...$panel,
            // Kurzanzeige der nächsten Ebene gemeinsam mit ihrer Detailtabelle: gleiche Breite.
            'schema' => [self::withRecord($panel['schema'][0], $panel['schema'][1] ?? null), ...array_slice($panel['schema'], 2)],
        ]);

        if ($panels->count() === 1) {
            return [...$components, Group::make($panels->first()['schema'])];
        }

        return [
            ...$components,
            Tabs::make('details-'.$level)
                // Eigener Schlüssel je Verknüpfung: Der aus dem Namen abgeleitete wäre bei zwei
                // Verknüpfungen auf dieselbe Tabelle gleich, die Reiter wären nicht umschaltbar.
                ->tabs($panels->map(fn (array $panel): Tab => Tab::make($panel['field']->label())
                    ->key('detail-'.$panel['field']->id)
                    ->schema($panel['schema']))->all())
                ->activeTab(max(1, $details->search(fn (DetailSource $d): bool => $d->id === $activeField) + 1)),
        ];
    }

    /**
     * Tabelle und ihre Kurzanzeige in einem Behälter, der nur so breit ist wie die Tabelle: Die Kurzanzeige
     * (Feldblöcke ohne eigene Breite) füllt ihn und ist damit genau so breit wie die Tabelle darüber.
     */
    private static function withRecord(Component $table, ?Component $record): Component
    {
        // Immer derselbe Behälter, auch ohne Auswahl: Änderte sich die Struktur um die (Detail-)Tabelle beim
        // Auswählen, baute Livewire sie neu auf und die Auswahl ginge verloren.
        return Group::make(array_values(array_filter([$table, $record])))->extraAttributes(['class' => 'saft-table-with-record']);
    }

    /**
     * @return array{path: CascadePath, levels: list<array{table: CustomTable, record: DynamicRecord, field: ?CustomField}>}
     */
    private function resolvedPath(): array
    {
        // Auswahl als genutzte Variable: Teil des once()-Schlüssels (ändert sich per Klick).
        $selection = $this->selection;

        return once(fn (): array => app(CascadeResolver::class)->resolve($this->customTable(), CascadePath::parse($selection)));
    }

    /** @return list<string> Felder, deren Spalte in der Liste gerade sichtbar ist (Spaltenauswahl der Person) */
    private function visibleColumnNames(CustomTable $table): array
    {
        return $table->fields
            // Verknüpfungsfelder heissen in der Liste „feld__label“ (DynamicTableBuilder::RELATION_SUFFIX).
            ->filter(fn (CustomField $f): bool => $f->is_active && ! $this->isTableColumnToggledHidden($f->type === FieldType::Relation ? $f->name.DynamicTableBuilder::RELATION_SUFFIX : $f->name))
            ->pluck('name')->values()->all();
    }

    private function dataView(): DataView
    {
        return $this->dataView ??= DataView::forMenu($this->viewId, $this->tableName);
    }

    private function customTable(): CustomTable
    {
        return $this->customTable ??= app(CustomTableLookup::class)->byName($this->tableName)
            ?? throw (new ModelNotFoundException)->setModel(CustomTable::class, [$this->tableName]);
    }
}
