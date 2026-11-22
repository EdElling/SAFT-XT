<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\DynamicData\DataAccess;
use App\DynamicData\Export\ExportFormat;
use App\DynamicData\Export\TableExporter;
use App\DynamicData\Import\RecordImportAnalysis;
use App\DynamicData\Import\RecordImporter;
use App\DynamicData\RecordCopier;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\ActivityChanges;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Support\AppLocale;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tabellen-Aktionen für Datensätze einer Designer-Tabelle (Liste und Detailtabellen).
 * Rechte jeweils über data.<tabelle>.* der betroffenen Tabelle.
 */
final class DynamicRecordActions
{
    /** Formularfeld der gewählten Kind-Tabellen beim Kopieren (kein gültiger Spaltenname, also kein Konflikt). */
    private const string COPY_CHILDREN = '_copy_children';

    /** Kaskade neu aufbauen (z. B. nach Löschen eines ausgewählten Datensatzes). */
    public const string REFRESH_EVENT = 'cascade-refresh';

    /**
     * @param  array<string, mixed>  $preset  feste Werte (z. B. Verknüpfung zur Haupttabelle)
     */
    public static function create(CustomTable $table, array $preset = []): CreateAction
    {
        return CreateAction::make()
            ->label(__('data.actions.create'))
            ->visible($table->supports('create'))
            ->schema(self::form($table, array_keys($preset), $preset))
            ->authorize(fn (): bool => DataAccess::current($table, 'create'))
            // Die Verknüpfung zur Haupttabelle wird immer gesetzt, auch wenn sie im Formular fehlt oder nur lesbar ist.
            ->using(fn (array $data): DynamicRecord => tap(DynamicRecord::forTable($table)->fill($data)->forceFill($preset))->save())
            ->after(fn ($livewire) => $livewire->dispatch(self::REFRESH_EVENT));
    }

    /**
     * Export der aktuellen Ansicht (Filter, Suche, Sortierung, Spalten) über alle Seiten.
     *
     * @param  list<string>|null  $visible  Spalten der Ansicht; null = Designer-Vorgabe
     * @param  list<string>  $except  z. B. Verknüpfungsfeld zur Haupttabelle in Detailtabellen
     */
    public static function export(CustomTable $table, ?array $visible = null, array $except = []): ActionGroup
    {
        return ActionGroup::make(array_map(
            static fn (ExportFormat $format): Action => self::exportAs($format, $table, $visible, $except),
            ExportFormat::cases(),
        ))
            ->label(__('data.export.label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->button();
    }

    /**
     * Felder des Exports: die gerade sichtbaren Spalten in ihrer Reihenfolge (Spaltenauswahl),
     * sonst die der Ansicht.
     *
     * @param  list<string>|null  $visible
     * @param  list<string>  $except
     * @return list<CustomField>
     */
    private static function exportFields(mixed $livewire, CustomTable $table, ?array $visible, array $except): array
    {
        $builder = app(DynamicTableBuilder::class);

        if (! $livewire instanceof HasTable) {
            return $builder->tableFields($table, $visible, $except);
        }

        $byName = collect($builder->availableFields($table, $except))->keyBy('name');
        $fields = [];

        foreach ($livewire->getTable()->getVisibleColumns() as $column) {
            $field = $byName->get(DynamicTableBuilder::fieldName($column->getName()));

            if ($field instanceof CustomField) {
                $fields[$field->name] = $field;
            }
        }

        return $fields === [] ? $builder->tableFields($table, $visible, $except) : array_values($fields);
    }

    /**
     * Datenimport (nur neue Datensätze): Vorlage herunterladen oder eine Excel-/CSV-Datei einlesen. Der Dialog prüft die
     * Datei zuerst vollständig und legt erst nach Bestätigung an – alles oder nichts.
     */
    /** Bezeichnungen der aktiven Dateifelder, kommagetrennt; leer, wenn keine. */
    private static function fileFieldLabels(CustomTable $table): string
    {
        return $table->fields
            ->filter(static fn (CustomField $f): bool => $f->is_active && $f->type === FieldType::File)
            ->map(static fn (CustomField $f): string => (string) $f->label)
            ->implode(', ');
    }

    public static function import(CustomTable $table): ActionGroup
    {
        return ActionGroup::make([
            Action::make('import_template')
                ->label(__('data.import.template'))
                ->icon('heroicon-o-document-arrow-down')
                ->authorize(fn (): bool => DataAccess::canImport($table))
                ->action(function () use ($table): StreamedResponse {
                    $path = app(RecordImporter::class)->template($table);

                    return response()->streamDownload(function () use ($path): void {
                        readfile($path);
                        @unlink($path);
                    }, __('data.import.template_file', ['table' => Str::slug((string) $table->label, '-', app()->getLocale()) ?: $table->name]).'.xlsx');
                }),
            Action::make('import_file')
                ->label(__('data.import.file_action'))
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize(fn (): bool => DataAccess::canImport($table))
                ->modalHeading(__('data.import.heading', ['table' => $table->label]))
                ->modalWidth('2xl')
                ->modalSubmitActionLabel(__('data.import.submit'))
                ->steps([
                    Step::make('file')
                        ->label(__('data.import.step_file'))
                        ->schema([
                            Text::make(__('data.import.intro')),
                            // Dateien lassen sich nicht importieren (B-021); auch Pflicht-Dateifelder bleiben leer.
                            Text::make(fn (): string => __('data.import.files_skipped', ['fields' => self::fileFieldLabels($table)]))
                                ->visible(fn (): bool => self::fileFieldLabels($table) !== ''),
                            FileUpload::make('file')
                                ->label(__('data.import.file'))
                                ->helperText(__('data.import.file_help', ['max' => config('dynamic_data.import.max_file_mb'), 'rows' => Number::format((int) config('dynamic_data.import.max_record_rows'), locale: AppLocale::numberLocale())]))
                                ->storeFiles(false)
                                ->maxSize((int) config('dynamic_data.import.max_file_mb') * 1024)
                                ->required(),
                            Hidden::make('summary'),
                        ])
                        ->afterValidation(function (Get $get, Set $set) use ($table): void {
                            try {
                                $file = self::uploaded($get('file'));
                                $analysis = app(RecordImporter::class)->analyze($table, $file->getRealPath(), $file->getClientOriginalName());
                            } catch (BusinessRuleException $e) {
                                Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                                throw new Halt;
                            }

                            if (! $analysis->isValid()) {
                                Notification::make()
                                    ->danger()
                                    ->persistent()
                                    ->title(__('data.import.invalid'))
                                    ->body(self::problemList($analysis))
                                    ->send();

                                throw new Halt;
                            }

                            $set('summary', [
                                'rows' => count($analysis->rows),
                                'columns' => $analysis->columns,
                                'ignored' => $analysis->ignored,
                            ]);
                        }),
                    Step::make('check')
                        ->label(__('data.import.step_check'))
                        ->schema([
                            Callout::make(fn (Get $get): string => trans_choice('data.import.ready', (int) ($get('summary')['rows'] ?? 0), ['count' => Number::format((int) ($get('summary')['rows'] ?? 0), locale: AppLocale::numberLocale())]))
                                ->success(),
                            Text::make(fn (Get $get): string => __('data.import.columns', ['columns' => implode(', ', (array) ($get('summary')['columns'] ?? []))])),
                            Text::make(fn (Get $get): string => __('data.import.ignored', ['columns' => implode(', ', (array) ($get('summary')['ignored'] ?? []))]))
                                ->visible(fn (Get $get): bool => ($get('summary')['ignored'] ?? []) !== []),
                            Text::make(__('data.import.only_new')),
                        ]),
                ])
                ->action(function (array $data, mixed $livewire) use ($table): void {
                    try {
                        $file = self::uploaded($data['file'] ?? null);
                        $count = app(RecordImporter::class)->import($table, $file->getRealPath(), $file->getClientOriginalName(), auth()->user());
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    } finally {
                        if (isset($file)) {
                            @unlink($file->getRealPath());
                        }
                    }

                    Notification::make()->success()
                        ->title(trans_choice('data.import.done', $count, ['count' => Number::format($count, locale: AppLocale::numberLocale())]))
                        ->send();
                }),
        ])
            ->label(__('data.import.label'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => DataAccess::canImport($table));
    }

    /**
     * Hochgeladene Datei aus dem Zustand des Upload-Felds. Ohne Speichern (storeFiles(false)) steht dort je nach
     * Schritt das Datei-Objekt oder der signierte Livewire-Verweis („livewire-file:…“).
     */
    private static function uploaded(mixed $state): TemporaryUploadedFile
    {
        // Ein einzelnes Datei-Objekt nicht mit (array) zerlegen, sondern als Liste mit einem Eintrag nehmen.
        foreach (is_array($state) ? collect($state)->flatten()->all() : [$state] as $file) {
            if (is_string($file) && TemporaryUploadedFile::canUnserialize($file)) {
                $file = TemporaryUploadedFile::unserializeFromLivewireRequest($file);
            }

            if ($file instanceof TemporaryUploadedFile && $file->exists()) {
                return $file;
            }
        }

        throw new BusinessRuleException(__('data.import.no_file'));
    }

    /** Fehlerliste für die Meldung: fehlende Pflichtspalten, Fehler mit Zeile und Spalte, Anzahl weiterer. */
    private static function problemList(RecordImportAnalysis $analysis): string
    {
        $items = [
            ...($analysis->missingRequired === [] ? [] : [__('data.import.missing_required', ['columns' => implode(', ', $analysis->missingRequired)])]),
            ...($analysis->rows === [] && $analysis->errorCount === 0 ? [__('data.import.no_rows')] : []),
            ...$analysis->messages,
        ];
        $more = $analysis->errorCount - count($analysis->messages);

        return '<ul style="list-style: disc; padding-inline-start: 1.25rem; max-height: 18rem; overflow-y: auto">'
            .collect($items)->map(fn (string $m): string => '<li>'.e($m).'</li>')->implode('')
            .($more > 0 ? '<li>'.e(trans_choice('data.import.more', $more, ['count' => $more])).'</li>' : '')
            .'</ul>';
    }

    /**
     * @param  list<string>|null  $visible
     * @param  list<string>  $except
     */
    private static function exportAs(ExportFormat $format, CustomTable $table, ?array $visible, array $except): Action
    {
        return Action::make('export_'.$format->value)
            ->label($format->label())
            ->authorize(fn (): bool => DataAccess::canExport($table))
            ->action(function (mixed $livewire) use ($format, $table, $visible, $except): ?StreamedResponse {
                $query = $livewire->getFilteredSortedTableQuery();
                $rows = (clone $query)->reorder()->count();
                $limit = (int) config('dynamic_data.export_max_rows');

                if ($rows > $limit) {
                    Notification::make()
                        ->title(__('data.export.too_large'))
                        ->body(__('data.export.too_large_body', ['rows' => Number::format($rows, locale: AppLocale::numberLocale()), 'limit' => Number::format($limit, locale: AppLocale::numberLocale())]))
                        ->warning()
                        ->send();

                    return null;
                }

                $table->loadMissing('fields');
                $fields = self::exportFields($livewire, $table, $visible, $except);
                $path = app(TableExporter::class)->write($query, $fields, $format, $table->label);

                activity('data')
                    ->performedOn($table)
                    ->event('exported')
                    ->withChanges(['attributes' => [
                        'format' => $format->value,
                        'zeilen' => $rows,
                        'filter' => array_filter((array) $livewire->tableFilters, self::hasFilterValue(...)),
                        'suche' => (string) $livewire->getTableSearch(),
                    ]])
                    ->log('exported');

                return response()->streamDownload(
                    static function () use ($path): void {
                        readfile($path);
                        unlink($path);
                    },
                    $table->name.'_'.now()->format('Y-m-d_Hi').'.'.$format->value,
                    ['Content-Type' => $format->contentType()],
                );
            });
    }

    /** Nur wirksame Filter protokollieren, das Filterformular enthält auch alle leeren. */
    private static function hasFilterValue(mixed $value): bool
    {
        return is_array($value)
            ? array_filter($value, self::hasFilterValue(...)) !== []
            : filled($value);
    }

    /** CSS-Klassen der Umschaltung nach Bildschirmbreite (PanelAppearance): schmal Slider „Details“, breit direkt. */
    public const string MOBILE = 'saft-mobile-only';

    public const string DESKTOP = 'saft-desktop-only';

    /**
     * Aktionen einer Zeile (Liste und Detailtabellen). Schmal (Smartphone): „Details“ im Slider und die Plugin-Aktionen
     * wie bisher. Breit (Desktop): nur Symbole mit Tooltip – Ansehen, Bearbeiten, Verlauf, Löschen (bei gelöschten
     * Datensätzen Wiederherstellen), dann die Plugin-Aktionen.
     *
     * @param  list<string>  $locked  nicht bearbeitbare Felder (Verknüpfung zur Haupttabelle)
     * @param  list<Action>  $plugins  Aktionen aus Plugins für einen Datensatz
     * @return list<Action|ActionGroup>
     */
    public static function rowActions(CustomTable $table, array $locked = [], array $plugins = []): array
    {
        if (! $table->hasKey()) {
            return [];
        }

        $desktop = ['class' => self::DESKTOP];
        $refresh = static fn ($livewire) => $livewire->dispatch(self::REFRESH_EVENT);
        $icon = static fn (Action $action): Action => $action->iconButton()->tooltip(fn (Action $action): string => (string) $action->getLabel())->extraAttributes($desktop);

        return [
            // Smartphone: wie bisher.
            self::details($table, $locked)->extraAttributes(['class' => self::MOBILE]),
            ...array_map(static fn (Action $plugin): Action => (clone $plugin)->name($plugin->getName().'Mobile')->extraAttributes(['class' => self::MOBILE]), $plugins),
            // Desktop: Ansehen, Bearbeiten, Verlauf, Löschen bzw. Wiederherstellen, Plugins.
            $icon(self::view($table, $locked, 'viewRow')),
            $icon(self::edit($table, $locked, 'editRow'))->after($refresh),
            $icon(self::copy($table, $locked, 'copyRow')),
            $icon(self::document($table, 'documentRow')),
            $icon(self::history('historyRow'))->visible(self::writable($table)),
            $icon(self::delete($table, 'deleteRow'))->after(fn ($livewire, DynamicRecord $record) => $livewire->dispatch(
                self::REFRESH_EVENT,
                deletedFrom: $table->name,
                deletedRecord: $table->hasSingleKey() ? (int) $record->getKey() : null,
            )),
            $icon(self::restore($table, 'restoreRow'))->after($refresh),
            ...array_map(static fn (Action $plugin): Action => self::pluginIcon($plugin), $plugins),
        ];
    }

    /** Plugin-Aktion am Desktop als Symbol; der Tooltip nennt die Bezeichnung und das Plugin. */
    private static function pluginIcon(Action $action): Action
    {
        if (blank($action->getIcon())) {
            return $action->extraAttributes(['class' => self::DESKTOP]);
        }

        $own = (fn (): mixed => $this->tooltip)->call($action);

        return $action->iconButton()->extraAttributes(['class' => self::DESKTOP])
            ->tooltip(static function (Action $action) use ($own): string {
                $text = $own === null ? '' : trim((string) $action->evaluate($own));
                $label = (string) $action->getLabel();

                return $text !== '' ? $label.' · '.$text : $label;
            });
    }

    /**
     * „Ansehen“: das Formular der Tabelle, alle Felder gesperrt, als mittiges Fenster.
     *
     * @param  list<string>  $locked
     */
    public static function view(CustomTable $table, array $locked = [], string $name = 'view'): ViewAction
    {
        return ViewAction::make($name)
            ->label(__('data.actions.view'))
            // Lupe: das Auge steht für Ein-/Ausblenden (Table-Designer).
            ->icon('heroicon-o-magnifying-glass')
            ->schema(self::form($table, $locked))
            ->modalHeading(fn (DynamicRecord $record): string => RecordEntries::title($table, $record))
            ->authorize(fn (): bool => DataAccess::current($table, 'view'));
    }

    /**
     * Popup mit allen Feldern; Bearbeiten, Verlauf, Löschen und Wiederherstellen nur hier
     * (die Kaskade selbst ist lesend). Auf breiten Bildschirmen ersetzt durch rowActions().
     *
     * @param  list<string>  $locked  nicht bearbeitbare Felder (Verknüpfung zur Haupttabelle)
     */
    public static function details(CustomTable $table, array $locked = []): Action
    {
        return Action::make('details')
            ->label(__('data.actions.details'))
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->slideOver()
            ->modalHeading(fn (DynamicRecord $record): string => RecordEntries::title($table, $record))
            ->schema(fn (DynamicRecord $record): array => [
                Callout::make(__('data.record_deleted'))->danger()->visible($record->trashed()),
                // Slide-in: hoch und schmal – eine einzige Liste „Name | Wert“.
                RecordEntries::blocks($table, $record, maxBlocks: 1),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('common.close'))
            ->extraModalFooterActions(fn (DynamicRecord $record): array => [
                self::edit($table, $locked)->record($record)->after(fn ($livewire) => $livewire->dispatch(self::REFRESH_EVENT)),
                self::copy($table, $locked)->record($record)->cancelParentActions(),
                self::document($table)->record($record),
                self::history()->record($record)->visible(self::writable($table)),
                self::delete($table)->record($record)->cancelParentActions()->after(fn ($livewire) => $livewire->dispatch(
                    self::REFRESH_EVENT,
                    deletedFrom: $table->name,
                    deletedRecord: $table->hasSingleKey() ? (int) $record->getKey() : null,
                )),
                self::restore($table)->record($record)->after(fn ($livewire) => $livewire->dispatch(self::REFRESH_EVENT)),
            ]);
    }

    /**
     * @param  list<string>  $locked  nicht bearbeitbare Felder
     */
    public static function edit(CustomTable $table, array $locked = [], ?string $name = null): EditAction
    {
        return EditAction::make($name)
            ->schema(self::form($table, $locked))
            ->visible($table->supports('update'))
            ->authorize(fn (): bool => DataAccess::current($table, 'update'))
            // Nur die Schaltfläche ausblenden: Ein bereits offenes Formular muss die Meldung beim Speichern erreichen.
            ->hidden(fn (mixed $livewire, ?DynamicRecord $record): bool => ($record?->trashed() ?? false)
                && array_intersect(['edit', 'editRow'], array_column($livewire->mountedActions ?? [], 'name')) === [])
            ->using(function (DynamicRecord $record, array $data) use ($locked, $table): DynamicRecord {
                // Das Formular kann älter sein als eine Löschung in einem anderen Fenster.
                $current = $record->newQuery()->withTrashed()->find($record->getKey());

                if ($current === null || $current->trashed()) {
                    throw new BusinessRuleException(__('errors.rules.record_deleted'), 'dynamic.record_deleted');
                }

                // Der Schlüssel einer angebundenen Tabelle ist nach dem Anlegen fest.
                $fixed = $table->isExternal() ? [...$locked, ...$table->keyColumns()] : $locked;
                $record->update(array_diff_key($data, array_flip($fixed)));

                return $record;
            });
    }

    /**
     * Datensatz kopieren (B-120): Formular „Neuer Datensatz“ mit den Werten des Originals; eindeutige Felder und der
     * Schlüssel einer angebundenen Tabelle bleiben leer, berechnete Felder rechnet SAFT neu. Dateien und Bilder werden
     * beim Speichern kopiert; die Verknüpfung zur Haupttabelle (Detailtabelle) bleibt die des Originals. Kind-Tabellen
     * mit „mitkopieren“ stehen als vorgewählte Häkchen über dem Formular (B-123).
     *
     * @param  list<string>  $locked  nicht bearbeitbare Felder (Verknüpfung zur Haupttabelle)
     */
    public static function copy(CustomTable $table, array $locked = [], string $name = 'copy'): Action
    {
        return Action::make($name)
            ->label(__('data.actions.copy'))
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn (?DynamicRecord $record): bool => $table->supports('create') && ! ($record?->trashed() ?? false))
            ->authorize(fn (): bool => DataAccess::current($table, 'create'))
            ->modalHeading(fn (DynamicRecord $record): string => __('data.actions.copy_heading', ['title' => RecordEntries::title($table, $record)]))
            ->modalSubmitActionLabel(__('data.actions.copy_submit'))
            ->schema(fn (Schema $schema, DynamicRecord $record): Schema => $schema
                ->components([
                    CheckboxList::make(self::COPY_CHILDREN)
                        ->label(__('data.copy.children'))
                        ->helperText(__('data.copy.children_help'))
                        ->options(app(RecordCopier::class)->options($table, $record))
                        ->visible(app(RecordCopier::class)->options($table, $record) !== [])
                        ->columnSpanFull(),
                    ...app(DynamicFormBuilder::class)->components($table, $locked, []),
                ])
                ->columns(2))
            ->fillForm(fn (DynamicRecord $record): array => [
                ...RecordCopier::values($table, $record),
                self::COPY_CHILDREN => array_keys(app(RecordCopier::class)->options($table, $record)),
            ])
            ->action(function (array $data, DynamicRecord $record, mixed $livewire) use ($table, $locked): void {
                $chosen = array_values((array) ($data[self::COPY_CHILDREN] ?? []));
                unset($data[self::COPY_CHILDREN]);
                $result = app(RecordCopier::class)->copy($table, $record, $data, $locked, $chosen);

                Notification::make()->success()->title(__('data.actions.copied'))
                    ->body($result['children'] > 0 ? trans_choice('data.copy.copied_children', $result['children'], ['count' => $result['children']]) : null)
                    ->send();
                $livewire->dispatch(self::REFRESH_EVENT);
            });
    }

    /**
     * Dokument aus einer Vorlage erzeugen (B-124, D1c): Vorlage wählen (bei mehreren), auf Wunsch im Dateifeld der Vorlage
     * ablegen, PDF herunterladen. Nur, wenn es für die Tabelle eine Vorlage gibt, die die Person verwenden darf.
     */
    public static function document(CustomTable $table, string $name = 'document'): Action
    {
        $generator = app(DocumentGenerator::class);
        $user = static fn (): ?User => auth()->user() instanceof User ? auth()->user() : null;
        $templates = static fn () => $generator->templatesFor($table, $user());
        $chosen = static fn (mixed $id) => $templates()->firstWhere('id', (int) $id) ?? $templates()->first();

        return Action::make($name)
            ->label(__('documents.generate.action'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(static fn (?DynamicRecord $record): bool => $templates()->isNotEmpty() && ! ($record?->trashed() ?? false))
            ->modalHeading(fn (DynamicRecord $record): string => __('documents.generate.heading', ['title' => RecordEntries::title($table, $record)]))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('documents.generate.submit'))
            ->schema(static fn (DynamicRecord $record): array => [
                Select::make('template')->label(__('documents.generate.template'))
                    ->options($templates()->mapWithKeys(static fn ($t): array => [$t->id => $t->name])->all())
                    ->default($templates()->first()?->id)
                    ->required()->selectablePlaceholder(false)->live()
                    ->visible($templates()->count() > 1),
                Toggle::make('store')
                    ->label(static fn (Get $get): string => __('documents.generate.store', ['field' => (string) $generator->storageField($chosen($get('template')), $table)?->label]))
                    ->helperText(__('documents.generate.store_help'))
                    ->default(true)
                    ->visible(static fn (Get $get): bool => $generator->canStore($chosen($get('template')), $table, $record, $user())),
            ])
            ->action(static function (array $data, DynamicRecord $record, mixed $livewire) use ($generator, $chosen, $user, $table) {
                $template = $chosen($data['template'] ?? null);
                $store = (bool) ($data['store'] ?? false) && $generator->canStore($template, $table, $record, $user());

                try {
                    $result = $generator->generate($template, $record, $user(), $store);
                } catch (BusinessRuleException $exception) {
                    if ($exception->errorCode !== 'documents.field_full') {
                        throw $exception;
                    }

                    // Feld voll: nicht ablegen, aber herunterladen.
                    Notification::make()->warning()->title($exception->getMessage())->send();
                    $result = $generator->generate($template, $record, $user(), false);
                }

                if ($result['file'] !== null) {
                    Notification::make()->success()->title(__('documents.generate.stored', ['name' => $result['file']->name]))->send();
                    $livewire->dispatch(self::REFRESH_EVENT);
                }

                $pdf = $result['document']->pdf;

                return response()->streamDownload(static function () use ($pdf): void {
                    echo $pdf;
                }, $result['file']?->name ?? $result['document']->fileName, ['Content-Type' => 'application/pdf']);
            });
    }

    /**
     * Sammelaktion „Dokumente erstellen“ (B-124, D3a) für die ausgewählten Datensätze: ein PDF zum Drucken oder ein ZIP mit
     * den einzelnen Dateien, auf Wunsch je Datensatz im Dateifeld der Vorlage abgelegt. Höchstens DocumentBatch::max().
     */
    public static function documentBulk(CustomTable $table): BulkAction
    {
        $generator = app(DocumentGenerator::class);
        $user = static fn (): ?User => auth()->user() instanceof User ? auth()->user() : null;
        $templates = static fn () => $generator->templatesFor($table, $user());
        $chosen = static fn (mixed $id) => $templates()->firstWhere('id', (int) $id) ?? $templates()->first();
        $canStore = static fn (mixed $id): bool => ($template = $chosen($id)) !== null
            && $generator->storageField($template, $table) !== null
            && $table->supports('update') && DataAccess::allows($user(), $table, 'update');

        return BulkAction::make('documentBulk')
            ->label(__('documents.batch.action'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(static fn (): bool => $table->hasSingleKey() && $templates()->isNotEmpty())
            ->modalHeading(static fn (EloquentCollection|Collection|null $records): string => trans_choice('documents.batch.heading', $records?->count() ?? 0, ['count' => $records?->count() ?? 0]))
            ->modalDescription(__('documents.batch.description', ['max' => DocumentBatch::max()]))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('documents.generate.submit'))
            ->schema([
                Select::make('template')->label(__('documents.generate.template'))
                    ->options(static fn (): array => $templates()->mapWithKeys(static fn ($t): array => [$t->id => $t->name])->all())
                    ->default(static fn (): ?int => $templates()->first()?->id)
                    ->required()->selectablePlaceholder(false)->live()
                    ->visible(static fn (): bool => $templates()->count() > 1),
                Radio::make('format')->label(__('documents.batch.format'))
                    ->options([DocumentBatch::PDF => __('documents.batch.formats.pdf'), DocumentBatch::ZIP => __('documents.batch.formats.zip')])
                    ->default(DocumentBatch::PDF)->required(),
                Toggle::make('store')
                    ->label(static fn (Get $get): string => __('documents.batch.store', ['field' => (string) $generator->storageField($chosen($get('template')), $table)?->label]))
                    ->helperText(__('documents.generate.store_help'))
                    ->default(true)
                    ->visible(static fn (Get $get): bool => $canStore($get('template'))),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(static function (array $data, EloquentCollection|Collection $records, mixed $livewire) use ($chosen, $canStore, $user) {
                $template = $chosen($data['template'] ?? null);

                try {
                    $result = app(DocumentBatch::class)->run(
                        $template,
                        $records->reject(static fn (DynamicRecord $record): bool => $record->trashed()),
                        $user(),
                        (bool) ($data['store'] ?? false) && $canStore($template?->id),
                        (string) ($data['format'] ?? DocumentBatch::PDF),
                    );
                } catch (BusinessRuleException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

                    return null;
                }

                Notification::make()
                    ->title(trans_choice('documents.batch.done', $result['generated'], ['count' => $result['generated'], 'stored' => $result['stored']]))
                    ->body($result['notes'] === [] ? null : implode("\n", $result['notes']))
                    ->{$result['notes'] === [] ? 'success' : 'warning'}()
                    ->persistent($result['notes'] !== [])
                    ->send();

                if ($result['stored'] > 0) {
                    $livewire->dispatch(self::REFRESH_EVENT);
                }

                $content = $result['content'];

                return response()->streamDownload(static function () use ($content): void {
                    echo $content;
                }, $result['file_name'], ['Content-Type' => $result['mime']]);
            });
    }

    public static function history(string $name = 'history'): Action
    {
        return Action::make($name)
            ->label(__('data.history.label'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('common.close'))
            ->schema([self::historyEntry(fn (DynamicRecord $record): array => self::historyRows($record))]);
    }

    /**
     * @param  \Closure  $state  von Filament ausgewertet, liefert historyRows()
     */
    public static function historyEntry(\Closure $state): RepeatableEntry
    {
        return RepeatableEntry::make('history')
            ->hiddenLabel()
            ->state($state)
            ->table([
                TableColumn::make(__('data.history.when')),
                TableColumn::make(__('data.history.who')),
                TableColumn::make(__('data.history.what')),
            ])
            ->schema([
                TextEntry::make('when'),
                TextEntry::make('who'),
                TextEntry::make('what'),
            ])
            ->placeholder(__('data.history.empty'));
    }

    public static function delete(CustomTable $table, ?string $name = null): DeleteAction
    {
        // Ein bereits gelöschter Datensatz wird nicht erneut gelöscht (Filaments eigene Regel ginge durch visible() verloren).
        $delete = DeleteAction::make($name)
            ->visible(fn (?Model $record = null): bool => $table->supports('delete') && ! self::trashed($record))
            ->authorize(fn (): bool => DataAccess::current($table, 'delete'));

        // Ohne deleted_at gibt es kein Wiederherstellen: ausdrücklich sagen, dass Löschen endgültig ist.
        return $table->isExternal() ? $delete->modalDescription(__('data.delete_permanently')) : $delete;
    }

    public static function restore(CustomTable $table, ?string $name = null): RestoreAction
    {
        // Nur bei gelöschten Datensätzen – visible() ersetzt Filaments eigene Sichtbarkeitsregel.
        return RestoreAction::make($name)
            ->visible(fn (?Model $record = null): bool => ! $table->isExternal() && self::trashed($record))
            ->authorize(fn (): bool => DataAccess::current($table, 'delete'));
    }

    private static function trashed(?Model $record): bool
    {
        return $record !== null && method_exists($record, 'trashed') && $record->trashed();
    }

    public static function deleteBulk(CustomTable $table): DeleteBulkAction
    {
        return DeleteBulkAction::make()->visible(! $table->isExternal())->authorize(fn (): bool => DataAccess::current($table, 'delete'));
    }

    /** Verlauf nur, wo über SAFT geschrieben werden kann; sonst gibt es nichts zu zeigen. */
    public static function writable(CustomTable $table): bool
    {
        return $table->isWritable();
    }

    /**
     * @param  list<string>  $except
     * @param  array<string, mixed>  $preset  feste Werte (z. B. Verknüpfung zur Haupttabelle)
     */
    public static function form(CustomTable $table, array $except = [], array $preset = []): \Closure
    {
        return fn (Schema $schema): Schema => $schema
            ->components(app(DynamicFormBuilder::class)->components($table, $except, $preset))
            ->columns(2);
    }

    /**
     * @return list<array{when: string, who: string, what: string}>
     */
    /**
     * Verlauf eines beliebigen protokollierten Objekts (z. B. eines Felds im Table-Designer, B-113) als Dialog.
     */
    public static function modelHistory(string $name = 'history'): Action
    {
        return Action::make($name)
            ->label(__('data.history.label'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('common.close'))
            ->schema([self::historyEntry(fn (Model $record): array => self::modelHistoryRows($record))]);
    }

    /** @return list<array{when: string, who: string, what: string}> neueste zuerst, höchstens 25 */
    public static function modelHistoryRows(Model $record): array
    {
        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->with('causer')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (Activity $a): array => [
                'when' => AppLocale::dateTime($a->created_at) ?? '—',
                'who' => ActivityChanges::causerLabel($a),
                'what' => ActivityChanges::eventLabel($a->event).': '.ActivityChanges::summary($a, 200),
            ])
            ->all();
    }

    public static function historyRows(DynamicRecord $record): array
    {
        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            // Text- und UUID-Schlüssel angebundener Tabellen stehen in den Eigenschaften.
            ->when(
                is_int($key = $record->activityKey()) || ctype_digit((string) $key),
                fn ($q) => $q->where('subject_id', $key),
                fn ($q) => $q->whereNull('subject_id')->where('properties->key', (string) $key),
            )
            ->with('causer')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (Activity $a): array => [
                'when' => AppLocale::dateTime($a->created_at) ?? '—',
                'who' => ActivityChanges::causerLabel($a),
                'what' => ActivityChanges::eventLabel($a->event).': '.ActivityChanges::summary($a, 200),
            ])
            ->all();
    }
}
