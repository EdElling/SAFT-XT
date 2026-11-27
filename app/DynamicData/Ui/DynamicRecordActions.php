<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Apps\CurrentApp;
use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentMailer;
use App\Documents\QrBill\QrBillFactory;
use App\Documents\Templates\TemplateException;
use App\Documents\Templates\TemplateRenderer;
use App\DynamicData\DataAccess;
use App\DynamicData\Export\ExportFormat;
use App\DynamicData\Import\RecordImportAnalysis;
use App\DynamicData\Import\RecordImporter;
use App\DynamicData\RecordCopier;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordVersion;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\StaleRecordException;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Filament\Support\ActivityChanges;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Support\AppLocale;
use App\Work\Types\DocumentsWork;
use App\Work\Types\ExportWork;
use App\Work\Types\RecordImportWork;
use App\Work\WorkService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
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
use Illuminate\Support\Facades\Storage;
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

    /** Versteckte Felder im Bearbeiten-Formular: gelesener Stand (RecordVersion) und bewusstes Überschreiben (F2). */
    public const string VERSION_FIELD = '_version';

    public const string OVERWRITE_FIELD = '_overwrite';

    public const string STALE_RELOAD_EVENT = 'stale-record-reload';

    public const string STALE_OVERWRITE_EVENT = 'stale-record-overwrite';

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
            ->using(fn (array $data): DynamicRecord => app(RecordWriter::class)->save(DynamicRecord::forTable($table)->fill($data)->forceFill($preset), WriteOptions::ui()))
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
                        // Bis check_rows Zeilen prüft der Assistent die Datei vollständig; grössere prüft die Arbeit.
                        ->afterValidation(function (Get $get, Set $set) use ($table): void {
                            try {
                                $file = self::uploaded($get('file'));
                                $checkRows = (int) config('dynamic_data.import.check_rows');
                                $analysis = app(RecordImporter::class)->analyze($table, $file->getRealPath(), $file->getClientOriginalName(), $checkRows > 0 ? $checkRows : null);
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
                                'rows' => $analysis->rows,
                                'columns' => $analysis->columns,
                                'ignored' => $analysis->ignored,
                                'checked' => $analysis->checked,
                            ]);
                        }),
                    Step::make('check')
                        ->label(__('data.import.step_check'))
                        ->schema([
                            Callout::make(fn (Get $get): string => trans_choice('data.import.ready', (int) ($get('summary')['rows'] ?? 0), ['count' => Number::format((int) ($get('summary')['rows'] ?? 0), locale: AppLocale::numberLocale())]))
                                ->success()
                                ->visible(fn (Get $get): bool => (bool) ($get('summary')['checked'] ?? true)),
                            Callout::make(fn (Get $get): string => trans_choice('data.import.ready_unchecked', (int) ($get('summary')['rows'] ?? 0), ['count' => Number::format((int) ($get('summary')['rows'] ?? 0), locale: AppLocale::numberLocale())]))
                                ->info()
                                ->visible(fn (Get $get): bool => ! ($get('summary')['checked'] ?? true)),
                            Text::make(fn (Get $get): string => __('data.import.columns', ['columns' => implode(', ', (array) ($get('summary')['columns'] ?? []))])),
                            Text::make(fn (Get $get): string => __('data.import.ignored', ['columns' => implode(', ', (array) ($get('summary')['ignored'] ?? []))]))
                                ->visible(fn (Get $get): bool => ($get('summary')['ignored'] ?? []) !== []),
                            Text::make(__('data.import.only_new')),
                        ]),
                ])
                // Als Arbeit (Fundament 2, F3b): die Datei wandert in den Ordner der Arbeit, die Aktion arbeitet kurz mit.
                // Fertig → Meldung wie bisher; sonst zur Seite der Arbeit, die prüft, einspielt und bei Fehlern alles zurücknimmt.
                ->action(function (array $data, mixed $livewire) use ($table): void {
                    try {
                        $file = self::uploaded($data['file'] ?? null);
                        $name = $file->getClientOriginalName();
                        $source = RecordImportWork::SOURCE.'.'.RecordImporter::format($name)->value;
                        $item = app(WorkService::class)->startAndAdvance(RecordImportWork::KEY, [
                            'table_id' => $table->id,
                            'file' => $name,
                            'source' => $source,
                            'rows' => (int) ($data['summary']['rows'] ?? 0),
                        ], auth()->user(), app(CurrentApp::class)->id(auth()->user()), files: [$source => $file->getRealPath()]);
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    } finally {
                        if (isset($file)) {
                            @unlink($file->getRealPath());
                        }
                    }

                    if ($item->isOpen()) {
                        $livewire->redirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));

                        return;
                    }

                    if ($item->status !== WorkItem::DONE) {
                        // Erste Zeile der Meldung ist die Überschrift, danach die Fehler mit Zeile und Spalte.
                        $lines = explode("\n", (string) $item->message);
                        Notification::make()->danger()->persistent()
                            ->title(__('data.import.failed'))
                            ->body(self::problemList(count($lines) > 1 ? array_slice($lines, 1) : $lines))
                            ->send();

                        return;
                    }

                    $count = (int) (((array) $item->result)['records'] ?? 0);
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

    /**
     * Fehlerliste für die Meldung: fehlende Pflichtspalten, Fehler mit Zeile und Spalte, Anzahl weiterer.
     *
     * @param  RecordImportAnalysis|list<string>  $problems
     */
    private static function problemList(RecordImportAnalysis|array $problems): string
    {
        if ($problems instanceof RecordImportAnalysis) {
            $more = $problems->errorCount - count($problems->messages);
            $problems = [
                ...($problems->missingRequired === [] ? [] : [(string) __('data.import.missing_required', ['columns' => implode(', ', $problems->missingRequired)])]),
                ...($problems->rows === 0 && $problems->errorCount === 0 ? [(string) __('data.import.no_rows')] : []),
                ...$problems->messages,
                ...($more > 0 ? [(string) trans_choice('data.import.more', $more, ['count' => $more])] : []),
            ];
        }

        return '<ul style="list-style: disc; padding-inline-start: 1.25rem; max-height: 18rem; overflow-y: auto">'
            .collect($problems)->map(fn (string $m): string => '<li>'.e($m).'</li>')->implode('')
            .'</ul>';
    }

    /**
     * Export als Arbeit (Fundament 2, F3b): Die Aktion startet sie und arbeitet kurz mit (work.inline_budget). Ist die
     * Datei dann fertig – der Normalfall –, kommt sie wie bisher direkt als Download; sonst geht es auf die Seite der
     * Arbeit, wo der Browser weiterarbeitet und die Datei danach 24 Stunden bereitsteht.
     *
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

                if ($limit > 0 && $rows > $limit) {
                    Notification::make()
                        ->title(__('data.export.too_large'))
                        ->body(__('data.export.too_large_body', ['rows' => Number::format($rows, locale: AppLocale::numberLocale()), 'limit' => Number::format($limit, locale: AppLocale::numberLocale())]))
                        ->warning()
                        ->send();

                    return null;
                }

                $table->loadMissing('fields');
                $user = auth()->user() instanceof User ? auth()->user() : null;
                $item = app(WorkService::class)->startAndAdvance(ExportWork::KEY, [
                    'table_id' => $table->id,
                    'fields' => array_map(static fn (CustomField $field): string => $field->name, self::exportFields($livewire, $table, $visible, $except)),
                    'format' => $format->value,
                    'title' => (string) $table->label,
                    'query' => EloquentSerializeFacade::serialize($query),
                    'rows' => $rows,
                    'file' => $table->name.'_'.now()->format('Y-m-d_Hi').'.'.$format->value,
                    'filter' => array_filter((array) $livewire->tableFilters, self::hasFilterValue(...)),
                    'search' => (string) $livewire->getTableSearch(),
                ], $user, app(CurrentApp::class)->id($user));

                if ($item->status === WorkItem::DONE && ($file = $item->resultFile()) !== null) {
                    $path = Storage::disk('local')->path($item->directory().'/'.$file);

                    return response()->streamDownload(static function () use ($path): void {
                        readfile($path);
                    }, $file, ['Content-Type' => $format->contentType()]);
                }

                if ($item->isOpen()) {
                    $livewire->redirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));

                    return null;
                }

                Notification::make()->danger()->title((string) $item->message)->persistent()->send();

                return null;
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
            ->schema(self::form($table, $locked, [], [Hidden::make(self::VERSION_FIELD), Hidden::make(self::OVERWRITE_FIELD)]))
            // Stand beim Öffnen: weicht er beim Speichern vom aktuellen ab, meldet der Writer „inzwischen geändert“ (F2).
            ->mutateRecordDataUsing(static fn (array $data, DynamicRecord $record): array => [...$data, self::VERSION_FIELD => RecordVersion::of($record), self::OVERWRITE_FIELD => false])
            ->visible($table->supports('update'))
            ->authorize(fn (): bool => DataAccess::current($table, 'update'))
            // Nur die Schaltfläche ausblenden: Ein bereits offenes Formular muss die Meldung beim Speichern erreichen.
            ->hidden(fn (mixed $livewire, ?DynamicRecord $record): bool => ($record?->trashed() ?? false)
                && array_intersect(['edit', 'editRow'], array_column($livewire->mountedActions ?? [], 'name')) === [])
            ->using(function (DynamicRecord $record, array $data, Action $action, mixed $livewire) use ($locked, $table): DynamicRecord {
                $version = isset($data[self::VERSION_FIELD]) ? (string) $data[self::VERSION_FIELD] : null;
                $overwrite = (bool) ($data[self::OVERWRITE_FIELD] ?? false);
                unset($data[self::VERSION_FIELD], $data[self::OVERWRITE_FIELD]);

                // Das Formular kann älter sein als eine Löschung in einem anderen Fenster.
                $current = $record->newQuery()->withTrashed()->find($record->getKey());

                if ($current === null || $current->trashed()) {
                    throw new BusinessRuleException(__('errors.rules.record_deleted'), 'dynamic.record_deleted');
                }

                // Der Schlüssel einer angebundenen Tabelle ist nach dem Anlegen fest.
                $fixed = $table->isExternal() ? [...$locked, ...$table->keyColumns()] : $locked;

                try {
                    return app(RecordWriter::class)->update($record, array_diff_key($data, array_flip($fixed)), WriteOptions::ui()->expecting($version, $overwrite));
                } catch (StaleRecordException $exception) {
                    self::notifyStale($exception, $livewire);

                    // Fenster bleibt offen, die Eingaben bleiben erhalten; weiter geht es über die Knöpfe der Meldung.
                    throw new Halt;
                }
            });
    }

    /**
     * Meldung „inzwischen geändert“ mit „Neu laden“ und „Trotzdem speichern“ (HandlesStaleRecords in der Komponente,
     * die das Formular offen hat).
     */
    private static function notifyStale(StaleRecordException $exception, mixed $livewire): void
    {
        $component = (string) $livewire->getId();

        Notification::make()
            ->danger()
            ->persistent()
            ->title($exception->title ?? __('errors.titles.stale_record'))
            ->body($exception->getMessage().' '.__('data.stale.hint'))
            ->actions([
                Action::make('reload')->label(__('data.stale.reload'))->button()
                    ->dispatch(self::STALE_RELOAD_EVENT, ['component' => $component])->close(),
                Action::make('overwrite')->label(__('data.stale.overwrite'))->color('gray')
                    ->dispatch(self::STALE_OVERWRITE_EVENT, ['component' => $component, 'version' => $exception->currentVersion])->close(),
            ])
            ->send();
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
        // Format (D4b): Wahl nur bei Vorlagen mit PDF und Word, sonst das Format der Vorlage.
        $format = static fn (?DocumentTemplate $template, mixed $state): string => $template !== null && is_string($state) && $template->allowsFormat($state)
            ? $state : ($template?->defaultFormat() ?? DocumentTemplate::FORMAT_PDF);
        $docx = static fn (Get $get): bool => $format($chosen($get('template')), $get('format')) === DocumentTemplate::FORMAT_DOCX;

        return Action::make($name)
            ->label(__('documents.generate.action'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(static fn (?DynamicRecord $record): bool => $templates()->isNotEmpty() && ! ($record?->trashed() ?? false))
            ->modalHeading(fn (DynamicRecord $record): string => __('documents.generate.heading', ['title' => RecordEntries::title($table, $record)]))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('documents.generate.submit'))
            // Vorgaben beim Öffnen, auch für die E-Mail (aus der Vorlage für diesen Datensatz, vor dem Senden änderbar).
            ->fillForm(static fn (DynamicRecord $record): array => [
                'template' => $templates()->first()?->id,
                'format' => $templates()->first()?->defaultFormat() ?? DocumentTemplate::FORMAT_PDF,
                'store' => true,
                'send' => false,
                ...self::mailDefaults($templates()->first(), $record, $user()),
            ])
            ->schema(static fn (DynamicRecord $record): array => [
                Select::make('template')->label(__('documents.generate.template'))
                    ->options($templates()->mapWithKeys(static fn ($t): array => [$t->id => $t->name])->all())
                    ->required()->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(static function (Set $set, ?string $state) use ($chosen, $record, $user): void {
                        foreach (self::mailDefaults($chosen($state), $record, $user()) as $key => $value) {
                            $set($key, $value);
                        }

                        $set('format', $chosen($state)?->defaultFormat() ?? DocumentTemplate::FORMAT_PDF);
                    })
                    ->visible($templates()->count() > 1),
                Radio::make('format')->label(__('documents.generate.format'))
                    ->options(self::formatOptions())->inline()->live()
                    ->visible(static fn (Get $get): bool => $chosen($get('template'))?->allowsFormat(DocumentTemplate::FORMAT_DOCX) === true
                        && $chosen($get('template'))?->allowsFormat(DocumentTemplate::FORMAT_PDF) === true),
                Text::make(static fn (Get $get): string => self::docxHint($chosen($get('template'))))
                    ->visible($docx),
                Toggle::make('store')
                    ->label(static fn (Get $get): string => __('documents.generate.store', ['field' => (string) $generator->storageField($chosen($get('template')), $table)?->label]))
                    ->helperText(__('documents.generate.store_help'))
                    ->visible(static fn (Get $get): bool => ! $docx($get) && $generator->canStore($chosen($get('template')), $table, $record, $user())),
                // Versand per E-Mail (B-124, D3b): Vorgaben aus der Vorlage, vor dem Senden änderbar; nur PDF.
                Toggle::make('send')->label(__('documents.mail.send'))->live()
                    ->visible(static fn (Get $get): bool => ! $docx($get) && DocumentMailer::canSend($user(), $chosen($get('template')))),
                TextInput::make('mail_to')->label(__('documents.mail.to'))->helperText(__('documents.mail.to_help'))
                    ->required()->maxLength(500)
                    ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                        [$valid, $invalid] = TemplateRenderer::addresses((string) $value);

                        if ($valid === [] || $invalid !== []) {
                            $fail(__('documents.mail.no_address', ['value' => implode(', ', $invalid)]));
                        }
                    })
                    ->visible(static fn (Get $get): bool => (bool) $get('send') && ! $docx($get)),
                TextInput::make('mail_subject')->label(__('documents.mail.subject'))->required()->maxLength(300)
                    ->visible(static fn (Get $get): bool => (bool) $get('send') && ! $docx($get)),
                Textarea::make('mail_body')->label(__('documents.mail.body'))->rows(6)
                    ->visible(static fn (Get $get): bool => (bool) $get('send') && ! $docx($get)),
                Hidden::make('mail_reply_to'),
                Toggle::make('mail_copy')->label(static fn (): string => __('documents.mail.copy', ['email' => (string) $user()?->email]))
                    ->visible(static fn (Get $get): bool => (bool) $get('send') && ! $docx($get) && filled($user()?->email)),
            ])
            ->action(static function (array $data, DynamicRecord $record, mixed $livewire) use ($generator, $chosen, $user, $table, $format) {
                $template = $chosen($data['template'] ?? null);
                $documentFormat = $format($template, $data['format'] ?? null);
                $pdf = $documentFormat === DocumentTemplate::FORMAT_PDF;
                // Word (D4b): nur herunterladen – nie ablegen oder versenden, verbindlich bleibt das PDF.
                $send = $pdf && (bool) ($data['send'] ?? false) && DocumentMailer::canSend($user(), $template);
                // Versendete Dokumente werden abgelegt, wenn möglich – nachvollziehbar, was verschickt wurde.
                $store = $pdf && ((bool) ($data['store'] ?? false) || $send) && $generator->canStore($template, $table, $record, $user());

                try {
                    $result = $generator->generate($template, $record, $user(), $store, $documentFormat);
                } catch (BusinessRuleException $exception) {
                    if ($exception->errorCode !== 'documents.field_full') {
                        throw $exception;
                    }

                    // Feld voll: nicht ablegen, aber herunterladen.
                    Notification::make()->warning()->title($exception->getMessage())->send();

                    try {
                        $result = $generator->generate($template, $record, $user(), false);
                    } catch (TemplateException $error) {
                        Notification::make()->danger()->title(__('documents.generate.failed'))->body($error->getMessage())->persistent()->send();

                        return null;
                    }
                } catch (TemplateException $exception) {
                    // Vorlage oder Daten nicht umsetzbar (z. B. ungültige IBAN für die QR-Rechnung): Meldung statt Fehlerseite.
                    Notification::make()->danger()->title(__('documents.generate.failed'))->body($exception->getMessage())->persistent()->send();

                    return null;
                }

                if ($result['file'] !== null) {
                    Notification::make()->success()->title(__('documents.generate.stored', ['name' => $result['file']->name]))->send();
                    $livewire->dispatch(self::REFRESH_EVENT);
                }

                if ($send) {
                    try {
                        $to = app(DocumentMailer::class)->send($template, $record, $user(), $result['document'], $result['file']?->name ?? $result['document']->fileName, $result['sha256'], [
                            'to' => (string) ($data['mail_to'] ?? ''),
                            'subject' => (string) ($data['mail_subject'] ?? ''),
                            'body' => (string) ($data['mail_body'] ?? ''),
                            'reply_to' => $data['mail_reply_to'] ?? null,
                        ], (bool) ($data['mail_copy'] ?? false));
                        Notification::make()->success()->title(__('documents.mail.sent', ['to' => implode(', ', $to)]))->send();
                    } catch (BusinessRuleException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->persistent()->send();
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title(DocumentMailer::failure($exception))->persistent()->send();
                    }
                }

                $content = $result['document']->content();

                return response()->streamDownload(static function () use ($content): void {
                    echo $content;
                }, $result['file']?->name ?? $result['document']->fileName, ['Content-Type' => $result['document']->mime()]);
            });
    }

    /** @return array<string, string> Auswahl PDF bzw. Word (D4b) */
    private static function formatOptions(): array
    {
        return [DocumentTemplate::FORMAT_PDF => __('documents.formats.pdf'), DocumentTemplate::FORMAT_DOCX => __('documents.formats.docx')];
    }

    /** Hinweis zur Word-Fassung: nur herunterladen (Sammelaktion: als ZIP); mit QR-Rechnung: Zahlteil nur im PDF. */
    private static function docxHint(?DocumentTemplate $template, bool $batch = false): string
    {
        return __($batch ? 'documents.generate.docx_hint_batch' : 'documents.generate.docx_hint')
            .(QrBillFactory::enabled($template?->qr_bill) ? ' '.__('documents.generate.docx_no_qr') : '');
    }

    /**
     * Vorgaben der E-Mail-Felder im Dialog aus der Vorlage für den Datensatz – nur mit dem Recht zum Versenden; bei einem
     * Fehler in der Vorlage leer (die Felder bleiben änderbar).
     *
     * @return array{mail_to: string, mail_subject: string, mail_body: string, mail_reply_to: ?string}
     */
    private static function mailDefaults(?DocumentTemplate $template, DynamicRecord $record, ?User $user): array
    {
        $mail = [];

        if ($template !== null && DocumentMailer::canSend($user, $template)) {
            try {
                $mail = app(DocumentMailer::class)->compose($template, $record, $user) ?? [];
            } catch (TemplateException) {
                $mail = [];
            }
        }

        return [
            'mail_to' => implode(', ', [...$mail['to'] ?? [], ...$mail['invalid'] ?? []]),
            'mail_subject' => (string) ($mail['subject'] ?? ''),
            'mail_body' => (string) ($mail['body'] ?? ''),
            'mail_reply_to' => $mail['reply_to'] ?? null,
        ];
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
        $format = static fn (?DocumentTemplate $template, mixed $state): string => $template !== null && is_string($state) && $template->allowsFormat($state)
            ? $state : ($template?->defaultFormat() ?? DocumentTemplate::FORMAT_PDF);
        $docx = static fn (Get $get): bool => $format($chosen($get('template')), $get('document')) === DocumentTemplate::FORMAT_DOCX;

        return BulkAction::make('documentBulk')
            ->label(__('documents.batch.action'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(static fn (): bool => $table->hasSingleKey() && $templates()->isNotEmpty())
            ->modalHeading(static fn (EloquentCollection|Collection|null $records): string => trans_choice('documents.batch.heading', $records?->count() ?? 0, ['count' => $records?->count() ?? 0]))
            ->modalDescription(static fn (): string => DocumentBatch::max() > 0 ? __('documents.batch.description_max', ['max' => DocumentBatch::max()]) : __('documents.batch.description'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('documents.generate.submit'))
            ->schema([
                Select::make('template')->label(__('documents.generate.template'))
                    ->options(static fn (): array => $templates()->mapWithKeys(static fn ($t): array => [$t->id => $t->name])->all())
                    ->default(static fn (): ?int => $templates()->first()?->id)
                    ->required()->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(static fn (Set $set, ?string $state) => $set('document', $chosen($state)?->defaultFormat() ?? DocumentTemplate::FORMAT_PDF))
                    ->visible(static fn (): bool => $templates()->count() > 1),
                // Format der Dokumente (D4b), nur bei Vorlagen mit PDF und Word; Word kommt immer als ZIP.
                Radio::make('document')->label(__('documents.generate.format'))
                    ->options(self::formatOptions())->inline()->live()
                    ->default(static fn (): string => $templates()->first()?->defaultFormat() ?? DocumentTemplate::FORMAT_PDF)
                    ->visible(static fn (Get $get): bool => $chosen($get('template'))?->allowsFormat(DocumentTemplate::FORMAT_DOCX) === true
                        && $chosen($get('template'))?->allowsFormat(DocumentTemplate::FORMAT_PDF) === true),
                Text::make(static fn (Get $get): string => self::docxHint($chosen($get('template')), true))
                    ->visible($docx),
                Radio::make('format')->label(__('documents.batch.format'))
                    ->options([DocumentBatch::PDF => __('documents.batch.formats.pdf'), DocumentBatch::ZIP => __('documents.batch.formats.zip')])
                    ->default(DocumentBatch::PDF)->required()
                    ->visible(static fn (Get $get): bool => ! $docx($get)),
                Toggle::make('store')
                    ->label(static fn (Get $get): string => __('documents.batch.store', ['field' => (string) $generator->storageField($chosen($get('template')), $table)?->label]))
                    ->helperText(__('documents.generate.store_help'))
                    ->default(true)
                    ->visible(static fn (Get $get): bool => ! $docx($get) && $canStore($get('template'))),
                // Versand per E-Mail (B-124, D3b): je Datensatz an die Adresse aus der Vorlage, Text aus der Vorlage; nur PDF.
                Toggle::make('send')->label(__('documents.batch.send'))->helperText(__('documents.batch.send_help'))->default(false)->live()
                    ->visible(static fn (Get $get): bool => ! $docx($get) && DocumentMailer::canSend($user(), $chosen($get('template')))),
                Toggle::make('mail_copy')->label(static fn (): string => __('documents.mail.copy', ['email' => (string) $user()?->email]))
                    ->visible(static fn (Get $get): bool => (bool) $get('send') && ! $docx($get) && filled($user()?->email)),
            ])
            ->deselectRecordsAfterCompletion()
            // Als Arbeit (Fundament 2, F3b): kurz mitarbeiten, fertig → Download wie bisher, sonst zur Seite der Arbeit.
            ->action(static function (array $data, EloquentCollection|Collection $records, mixed $livewire) use ($chosen, $canStore, $user, $format) {
                $template = $chosen($data['template'] ?? null);

                if ($template === null) {
                    return null;
                }

                $document = $format($template, $data['document'] ?? null);
                $pdf = $document === DocumentTemplate::FORMAT_PDF;
                $ids = $records->reject(static fn (DynamicRecord $record): bool => $record->trashed())->values()->map(static fn (DynamicRecord $record): mixed => $record->getKey())->all();
                $output = $pdf ? (string) ($data['format'] ?? DocumentBatch::PDF) : DocumentBatch::ZIP;

                if (DocumentBatch::max() > 0 && count($ids) > DocumentBatch::max()) {
                    Notification::make()->danger()->title(__('documents.batch.too_many', ['count' => count($ids), 'max' => DocumentBatch::max()]))->persistent()->send();

                    return null;
                }

                $item = app(WorkService::class)->startAndAdvance(DocumentsWork::KEY, [
                    'template_id' => $template->id,
                    'record_ids' => $ids,
                    'store' => $pdf && (bool) ($data['store'] ?? false) && $canStore($template->id),
                    'format' => $output,
                    'send' => $pdf && (bool) ($data['send'] ?? false),
                    'copy' => (bool) ($data['mail_copy'] ?? false),
                    'document' => $document,
                    'file' => DocumentBatch::fileName($template, $output),
                ], $user(), app(CurrentApp::class)->id($user()));

                if ($item->isOpen()) {
                    $livewire->redirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));

                    return null;
                }

                if ($item->status !== WorkItem::DONE || ($file = $item->resultFile()) === null) {
                    Notification::make()->danger()->title((string) $item->message)->persistent()->send();

                    return null;
                }

                $result = (array) $item->result;
                $notes = (array) ($result['notes'] ?? []);
                Notification::make()
                    ->title(trans_choice('documents.batch.done', (int) $result['generated'], ['count' => (int) $result['generated'], 'stored' => (int) $result['stored']])
                        .($pdf && ($data['send'] ?? false) ? ', '.trans_choice('documents.batch.sent', (int) $result['sent'], ['count' => (int) $result['sent']]) : ''))
                    ->body($notes === [] ? null : implode("\n", $notes))
                    ->{$notes === [] ? 'success' : 'warning'}()
                    ->persistent($notes !== [])
                    ->send();

                if ((int) $result['stored'] > 0) {
                    $livewire->dispatch(self::REFRESH_EVENT);
                }

                $path = Storage::disk('local')->path($item->directory().'/'.$file);

                return response()->streamDownload(static function () use ($path): void {
                    readfile($path);
                }, $file, ['Content-Type' => $output === DocumentBatch::ZIP ? 'application/zip' : 'application/pdf']);
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
            ->authorize(fn (): bool => DataAccess::current($table, 'delete'))
            ->using(static function (DynamicRecord $record): bool {
                app(RecordWriter::class)->delete($record, WriteOptions::ui());

                return true;
            });

        // Ohne deleted_at gibt es kein Wiederherstellen: ausdrücklich sagen, dass Löschen endgültig ist.
        return $table->isExternal() ? $delete->modalDescription(__('data.delete_permanently')) : $delete;
    }

    public static function restore(CustomTable $table, ?string $name = null): RestoreAction
    {
        // Nur bei gelöschten Datensätzen – visible() ersetzt Filaments eigene Sichtbarkeitsregel.
        return RestoreAction::make($name)
            ->visible(fn (?Model $record = null): bool => ! $table->isExternal() && self::trashed($record))
            ->authorize(fn (): bool => DataAccess::current($table, 'delete'))
            ->using(static function (DynamicRecord $record): bool {
                app(RecordWriter::class)->restore($record, WriteOptions::ui());

                return true;
            });
    }

    private static function trashed(?Model $record): bool
    {
        return $record !== null && method_exists($record, 'trashed') && $record->trashed();
    }

    /** Verlauf nur, wo über SAFT geschrieben werden kann; sonst gibt es nichts zu zeigen. */
    public static function writable(CustomTable $table): bool
    {
        return $table->isWritable();
    }

    /**
     * @param  list<string>  $except
     * @param  array<string, mixed>  $preset  feste Werte (z. B. Verknüpfung zur Haupttabelle)
     * @param  list<Component>  $extra  weitere Komponenten, z. B. versteckte Felder
     */
    public static function form(CustomTable $table, array $except = [], array $preset = [], array $extra = []): \Closure
    {
        return fn (Schema $schema): Schema => $schema
            ->components([...app(DynamicFormBuilder::class)->components($table, $except, $preset), ...$extra])
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
                'what' => ActivityChanges::eventLabel($a->event)
                    // Sammeländerung (B-133): Herkunft aus WriteOptions::$origin.
                    .(($a->properties['via'] ?? null) === WriteOptions::BULK ? ' ('.__('data.bulk.history').')' : '')
                    .': '.ActivityChanges::summary($a, 200),
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
                'what' => ActivityChanges::eventLabel($a->event)
                    // Sammeländerung (B-133): Herkunft aus WriteOptions::$origin.
                    .(($a->properties['via'] ?? null) === WriteOptions::BULK ? ' ('.__('data.bulk.history').')' : '')
                    .': '.ActivityChanges::summary($a, 200),
            ])
            ->all();
    }
}
