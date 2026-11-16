<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\DataAccess;
use App\DynamicData\Export\ExportFormat;
use App\DynamicData\Export\TableExporter;
use App\DynamicData\Import\RecordImportAnalysis;
use App\DynamicData\Import\RecordImporter;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\ActivityChanges;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Support\AppLocale;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Popup mit allen Feldern; Bearbeiten, Verlauf, Löschen und Wiederherstellen nur hier
     * (die Kaskade selbst ist lesend).
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
    public static function edit(CustomTable $table, array $locked = []): EditAction
    {
        return EditAction::make()
            ->schema(self::form($table, $locked))
            ->visible($table->supports('update'))
            ->authorize(fn (): bool => DataAccess::current($table, 'update'))
            // Nur die Schaltfläche ausblenden: Ein bereits offenes Formular muss die Meldung beim Speichern erreichen.
            ->hidden(fn (mixed $livewire, ?DynamicRecord $record): bool => ($record?->trashed() ?? false)
                && ! in_array('edit', array_column($livewire->mountedActions ?? [], 'name'), true))
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

    public static function history(): Action
    {
        return Action::make('history')
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

    public static function delete(CustomTable $table): DeleteAction
    {
        // Ein bereits gelöschter Datensatz wird nicht erneut gelöscht (Filaments eigene Regel ginge durch visible() verloren).
        $delete = DeleteAction::make()
            ->visible(fn (?Model $record = null): bool => $table->supports('delete') && ! self::trashed($record))
            ->authorize(fn (): bool => DataAccess::current($table, 'delete'));

        // Ohne deleted_at gibt es kein Wiederherstellen: ausdrücklich sagen, dass Löschen endgültig ist.
        return $table->isExternal() ? $delete->modalDescription(__('data.delete_permanently')) : $delete;
    }

    public static function restore(CustomTable $table): RestoreAction
    {
        // Nur bei gelöschten Datensätzen – visible() ersetzt Filaments eigene Sichtbarkeitsregel.
        return RestoreAction::make()
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
