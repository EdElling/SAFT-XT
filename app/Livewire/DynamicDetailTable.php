<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DynamicData\CustomTableLookup;
use App\DynamicData\DataAccess;
use App\DynamicData\DetailRelations;
use App\DynamicData\DetailSource;
use App\DynamicData\Ui\BulkRecordActions;
use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\DynamicData\Ui\KeylessRow;
use App\Filament\Concerns\HandlesStaleRecords;
use App\Filament\Concerns\RemembersTableColumns;
use App\Models\DynamicRecord;
use App\Plugins\Api\DataAction;
use App\Plugins\PluginRegistry;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Detailtabelle einer Kaskaden-Ebene: Datensätze der Kindtabelle, deren
 * Verknüpfungsfeld auf den ausgewählten Datensatz der Ebene darüber zeigt.
 * Klick auf eine Zeile wählt sie aus (nächste Ebene erscheint darunter); lesend,
 * Bearbeiten im Popup "Details". Verknüpfung ist vorbelegt und gesperrt.
 */
class DynamicDetailTable extends Component implements HasActions, HasSchemas, HasTable
{
    use HandlesStaleRecords;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable, RemembersTableColumns {
        getTableRecordKey as private filamentTableRecordKey;
        resolveTableRecord as private filamentResolveTableRecord;
        RemembersTableColumns::loadTableColumnsFromSession insteadof InteractsWithTable;
        RemembersTableColumns::persistTableColumns insteadof InteractsWithTable;
    }

    #[Locked]
    public string $parentTable = '';

    #[Locked]
    public int|string $parentId = 0;

    #[Locked]
    public int $relationFieldId = 0;

    #[Locked]
    public int $level = 1;

    /** Ausgewählte Zeile – kommt von der Seite (Auswahlpfad), daher reaktiv. */
    #[Reactive]
    public int|string|null $selectedId = null;

    private ?DetailSource $relationField = null;

    private bool $parentValueLoaded = false;

    private mixed $parentValue = null;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    /** Klick auf eine Zeile (Filament ruft die Methode mit dem Schlüssel auf). */
    public function selectRecord(int|string $key): void
    {
        $child = $this->relationField()->child;

        if (! $child->hasSingleKey()) {
            return;
        }

        $recordId = DynamicRecord::forTable($child->loadMissing('fields'))->getKeyType() === 'int' ? (int) $key : (string) $key;
        $this->dispatch('cascade-select', level: $this->level, fieldId: $this->relationFieldId, recordId: $recordId);
    }

    /** Zeilen ohne einzelnen Schlüssel (z. B. Zuordnungstabellen) sind nur lesbar, nicht auswählbar. */
    public function getTableRecordKey(Model|array $record): string
    {
        $table = $this->relationField()->child;

        if ($record instanceof Model && ! $table->hasSingleKey()) {
            return KeylessRow::key($table, $record);
        }

        return $this->filamentTableRecordKey($record);
    }

    /** Zeilen ohne einzelnen Schlüssel über die Werte in ihrer Kennung wiederfinden. */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        $table = $this->relationField()->child;

        if ($key === null || $table->hasSingleKey()) {
            return $this->filamentResolveTableRecord($key);
        }

        $query = $this->applyFiltersToTableQuery($this->getTable()->getQuery(isResolvingRecord: true), isResolvingRecord: true);

        return KeylessRow::constrain($table, $query, $key)?->first();
    }

    public function table(Table $table): Table
    {
        $field = $this->relationField();
        $child = $field->child;
        $child->loadMissing('fields');
        $builder = app(DynamicTableBuilder::class);
        $fk = $field->column();
        $value = $this->parentValue();
        $external = $child->isExternal();
        $selectable = $child->hasSingleKey();
        $sort = $child->keyColumn() ?? $builder->tableFields($child, null, [$fk])[0]->name ?? $fk;

        return $table
            // Eigene Sitzungsschlüssel je Detailbeziehung (alle Detailtabellen teilen diese Klasse).
            ->identifier('detail.'.$field->id)
            ->heading($field->label())
            // Ohne Wert in der Elternspalte (nur bei Tabellenverknüpfungen möglich) gibt es keine Details.
            ->query(fn () => $builder->query($child, null, [$fk], toggleable: true)->when($value === null, fn ($q) => $q->whereNull($fk)->whereNotNull($fk), fn ($q) => $q->where($fk, $value)))
            // Spaltenauswahl und Reihenfolge wie in der Liste, je Person und Detailbeziehung gespeichert.
            ->columns($builder->columns($child, null, [$fk], toggleable: true))
            ->reorderableColumns()
            ->filters([...$builder->filters($child, [$fk]), TrashedFilter::make()->visible(! $external && DataAccess::current($child, 'delete'))])
            ->filtersFormColumns(3)
            ->defaultSort($sort)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->modelLabel(__('data.record'))
            ->pluralModelLabel($child->label)
            ->recordAction($selectable ? 'selectRecord' : null)
            ->recordClasses(fn (DynamicRecord $record): ?string => $selectable && $this->selectedId !== null && (string) $record->getKey() === (string) $this->selectedId ? 'saft-row-selected' : null)
            ->headerActions([
                DynamicRecordActions::create($child, [$fk => $value])
                    ->visible(fn (): bool => $value !== null && $this->parentIsActive()),
                DynamicRecordActions::export($child, null, [$fk]),
                ...app(PluginRegistry::class)->dataActions($child, DataAction::TABLE),
            ])
            ->recordActions([
                ...DynamicRecordActions::rowActions($child, [$fk], $child->hasKey() ? app(PluginRegistry::class)->dataActions($child, DataAction::RECORD) : []),
            ])
            // Sammelaktionen wie in der Datenansicht (B-133); die Verknüpfung zur Haupttabelle bleibt fest.
            ->toolbarActions([
                BulkRecordActions::edit($child, [$fk]),
                DynamicRecordActions::exportSelected($child, null, [$fk]),
                DynamicRecordActions::documentBulk($child),
                BulkRecordActions::delete($child),
                BulkRecordActions::restore($child),
            ])
            ->emptyStateHeading(__('data.empty_details'));
    }

    /** Bis ein nicht aktiver Reiter sichtbar wird und nachlädt (B-151). */
    public function placeholder(): View
    {
        return view('livewire.dynamic-detail-table-placeholder');
    }

    public function render(): View
    {
        return view('livewire.dynamic-detail-table');
    }

    /** Neue Datensätze dürfen nicht auf einen gelöschten Datensatz verweisen. */
    private function parentIsActive(): bool
    {
        return DynamicRecord::forTableName($this->parentTable)->newQuery()->whereKey($this->parentId)->exists();
    }

    /** Verknüpfung muss für die Haupttabelle konfiguriert sein; Leserecht auf beide. */
    private function authorizeAccess(): void
    {
        $field = $this->relationField();
        $parent = app(CustomTableLookup::class)->byName($this->parentTable) ?? abort(404);

        abort_unless(DataAccess::current($parent, 'view') && DataAccess::current($field->child, 'view'), 403);
    }

    /**
     * Wert, den die Details tragen: bei Verknüpfungsfeldern der Schlüssel des Elterndatensatzes, bei
     * Tabellenverknüpfungen der Wert seiner Zielspalte (dafür wird er gelesen).
     */
    private function parentValue(): mixed
    {
        if (! $this->relationField()->isLink()) {
            return $this->parentId;
        }

        if (! $this->parentValueLoaded) {
            $parent = DynamicRecord::forTableName($this->parentTable)->newQuery()->withoutGlobalScopes()->find($this->parentId);
            $this->parentValue = $parent === null ? null : $this->relationField()->parentValue($parent);
            $this->parentValueLoaded = true;
        }

        return $this->parentValue;
    }

    private function relationField(): DetailSource
    {
        if ($this->relationField !== null) {
            return $this->relationField;
        }

        $parent = app(CustomTableLookup::class)->byName($this->parentTable);

        return $this->relationField = ($parent === null ? null : DetailRelations::configuredFor($parent)->firstWhere('id', $this->relationFieldId))
            ?? abort(404);
    }
}
