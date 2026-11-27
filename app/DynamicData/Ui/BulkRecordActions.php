<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\Apps\CurrentApp;
use App\DynamicData\Bulk\BulkChange;
use App\DynamicData\Bulk\BulkPreview;
use App\DynamicData\DataAccess;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\Types\BulkRecordsWork;
use App\Work\WorkService;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sammelaktionen der Datenansicht und der Detailtabellen (B-133, BULK_EDIT.md): „Ändern …“ (Sammeländerung als Arbeit
 * bulk_records), Löschen und Wiederherstellen der ausgewählten Datensätze. Ausgewählt heisst immer: geschnitten mit dem
 * aktuellen Filter (Filament bildet die Abfrage aus Auswahl und Filter).
 */
final class BulkRecordActions
{
    /** Zustand des Dialogs: changes.<feld>.on|op|<feld> */
    private const string STATE = 'changes';

    /**
     * Dialog „Ändern …“: je änderbarem Feld ein Häkchen, die Art (setzen/leeren) und das Eingabefeld des Feldtyps;
     * darunter die Vorschau. „N Datensätze ändern“ bzw. „Nur prüfen“ starten die Arbeit; kleine Mengen sind im Request
     * fertig, grössere leiten auf die Seite der Arbeit weiter.
     *
     * @param  list<string>  $locked  nicht änderbare Felder (Verknüpfung zur Haupttabelle in einer Detailtabelle)
     */
    public static function edit(CustomTable $table, array $locked = []): BulkAction
    {
        $ids = self::selectedIds($table);

        return BulkAction::make('bulkEdit')
            ->label(__('data.bulk.action'))
            ->icon('heroicon-o-pencil-square')
            ->visible(static fn (): bool => BulkChange::allowed($table) && BulkChange::editableFields($table, $locked) !== [])
            ->authorize(static fn (): bool => BulkChange::allowed($table))
            // Im Filter „nur gelöschte“ gibt es nichts zu ändern (wie Filaments Sammel-Löschen).
            ->hidden(static function (HasTable $livewire): bool {
                $trashed = $livewire->getTableFilterState(TrashedFilter::class) ?? [];

                return array_key_exists('value', $trashed) && ! $trashed['value'] && filled($trashed['value']);
            })
            ->modalHeading(__('data.bulk.heading'))
            ->modalDescription(static fn (BulkAction $action): string => trans_choice('data.bulk.selected', $count = count($ids($action)), ['count' => $count]))
            ->modalWidth('4xl')
            ->schema(static fn (BulkAction $action): array => [
                Section::make(__('data.bulk.fields'))
                    ->description(__('data.bulk.fields_help'))
                    ->schema(self::fieldRows($table, $locked))
                    ->compact(),
                View::make('filament.data.bulk-preview')
                    ->viewData(static fn (Get $get): array => self::previewData($table, $locked, (array) $get(self::STATE), $ids($action))),
            ])
            ->modalSubmitActionLabel(static fn (BulkAction $action): string => trans_choice('data.bulk.submit', $count = count($ids($action)), ['count' => $count]))
            ->extraModalFooterActions(static fn (BulkAction $action): array => [
                $action->makeModalSubmitAction('bulkCheck', ['dry_run' => true])->label(__('data.bulk.check'))->icon('heroicon-o-check-badge'),
            ])
            ->action(static function (array $data, array $arguments, BulkAction $action, mixed $livewire) use ($table, $locked, $ids): void {
                $dryRun = (bool) ($arguments['dry_run'] ?? false);
                $change = BulkChange::fromArray($table, self::changes($table, $locked, (array) ($data[self::STATE] ?? [])), $locked);
                $keys = $ids($action);

                try {
                    $change->validate();

                    if (BulkRecordsWork::max() > 0 && count($keys) > BulkRecordsWork::max()) {
                        throw new BusinessRuleException(__('data.bulk.errors.too_many', ['count' => count($keys), 'max' => BulkRecordsWork::max()]), 'bulk.too_many');
                    }
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                }

                $user = auth()->user() instanceof User ? auth()->user() : null;
                $item = app(WorkService::class)->startAndAdvance(BulkRecordsWork::KEY, [
                    'table_id' => $table->id,
                    'changes' => $change->toArray(),
                    'locked' => $locked,
                    'record_ids' => $keys,
                    'dry_run' => $dryRun,
                ], $user, app(CurrentApp::class)->id($user));

                if ($item->isOpen()) {
                    $livewire->redirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));

                    return;
                }

                [$title, $body] = self::split((string) $item->message);
                $notification = Notification::make()->title($title)->body($body);

                if ($item->status !== WorkItem::DONE) {
                    $notification->danger()->persistent()->send();

                    // Dialog bleibt offen: Auswahl oder Werte anpassen.
                    throw new Halt;
                }

                $notification->success()->send();

                if ($dryRun) {
                    throw new Halt;
                }

                $livewire->dispatch(DynamicRecordActions::REFRESH_EVENT);
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Löschen der ausgewählten Datensätze: gesperrte (Löschsperre) übersprungen und mit Grund gemeldet. */
    public static function delete(CustomTable $table): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->visible(static fn (): bool => ! $table->isExternal() && DataAccess::current($table, 'delete'))
            ->authorize(static fn (): bool => DataAccess::current($table, 'delete'))
            ->before(static function (EloquentCollection|Collection $records): void {
                self::assertWithinLimit($records->count());
            })
            // Je Datensatz ein Schreibvorgang über den Writer (Löschsperre, Summen, Ereignisse, Transaktion), alle mit
            // derselben Kennung im Protokoll.
            ->using(static function (DeleteBulkAction $action, EloquentCollection|Collection $records): void {
                $options = WriteOptions::bulk(BulkRecordsWork::RUN_PREFIX.Str::uuid());

                foreach ($records as $record) {
                    /** @var DynamicRecord $record */
                    if ($record->trashed()) {
                        continue;
                    }

                    try {
                        app(RecordWriter::class)->delete($record, $options);
                    } catch (BusinessRuleException $e) {
                        $action->reportBulkProcessingFailure('blocked', static fn (int $failureCount): string => trans_choice('data.bulk.delete_blocked', $failureCount, ['count' => $failureCount]));
                    } catch (\Throwable $e) {
                        report($e);
                        $action->reportBulkProcessingFailure();
                    }
                }
            });
    }

    /** Wiederherstellen ausgewählter Datensätze aus dem Papierkorb (Filter „gelöschte“). */
    public static function restore(CustomTable $table): RestoreBulkAction
    {
        return RestoreBulkAction::make()
            ->visible(static fn (): bool => ! $table->isExternal() && DataAccess::current($table, 'delete'))
            ->authorize(static fn (): bool => DataAccess::current($table, 'delete'))
            ->before(static function (EloquentCollection|Collection $records): void {
                self::assertWithinLimit($records->count());
            })
            ->using(static function (RestoreBulkAction $action, EloquentCollection|Collection $records): void {
                $options = WriteOptions::bulk(BulkRecordsWork::RUN_PREFIX.Str::uuid());

                foreach ($records as $record) {
                    /** @var DynamicRecord $record */
                    if (! $record->trashed()) {
                        continue;
                    }

                    try {
                        app(RecordWriter::class)->restore($record, $options);
                    } catch (BusinessRuleException $e) {
                        $action->reportBulkProcessingFailure('rejected', $e->getMessage());
                    } catch (\Throwable $e) {
                        report($e);
                        $action->reportBulkProcessingFailure();
                    }
                }
            });
    }

    /**
     * Schlüssel der ausgewählten, nicht gelöschten Datensätze, geschnitten mit dem Filter; je Aktion einmal berechnet.
     *
     * @return \Closure(BulkAction): list<int|string>
     */
    private static function selectedIds(CustomTable $table): \Closure
    {
        $cache = new \WeakMap;

        return static function (BulkAction $action) use (&$cache): array {
            if (isset($cache[$action])) {
                return $cache[$action];
            }

            $query = $action->getSelectedRecordsQuery();
            $model = $query->getModel();

            if ($model instanceof DynamicRecord && $model->hasSoftDeleteColumn()) {
                $query->whereNull($model->qualifyColumn('deleted_at'));
            }

            return $cache[$action] = $query->reorder()->pluck($model->getQualifiedKeyName())->unique()->values()->all();
        };
    }

    /**
     * @param  list<string>  $locked
     * @return list<Group>
     */
    private static function fieldRows(CustomTable $table, array $locked): array
    {
        $builder = app(DynamicFormBuilder::class);
        $rows = [];

        foreach (BulkChange::editableFields($table, $locked) as $name => $field) {
            $ops = array_values(array_filter([
                BulkChange::canSet($field) ? BulkChange::SET : null,
                BulkChange::canClear($field) ? BulkChange::CLEAR : null,
            ]));
            $isOn = static fn (Get $get): bool => (bool) $get('on');
            $op = static fn (Get $get): string => (string) ($get('op') ?? $ops[0]);

            $rows[] = Group::make([
                Checkbox::make('on')->label($field->label)->live(),
                ToggleButtons::make('op')
                    ->hiddenLabel()
                    ->options(collect($ops)->mapWithKeys(static fn (string $o): array => [$o => __("data.bulk.ops.{$o}")])->all())
                    ->default($ops[0])
                    ->inline()
                    ->grouped()
                    ->live()
                    ->disabled(count($ops) === 1)
                    ->visible($isOn),
                $builder->single($table, $field)
                    ->hiddenLabel()
                    // Vorschau: Auswahl, Datum, Ja/Nein sofort; Texteingaben beim Verlassen des Felds.
                    ->live(onBlur: in_array($field->type, [FieldType::String, FieldType::Text, FieldType::Integer, FieldType::Decimal], true))
                    ->visible(static fn (Get $get): bool => $isOn($get) && $op($get) === BulkChange::SET)
                    ->columnSpan(['default' => 1, 'md' => 1]),
            ])->statePath(self::STATE.'.'.$name)->columns(['default' => 1, 'md' => 3]);
        }

        return $rows;
    }

    /**
     * Aus dem Zustand des Dialogs die Änderungen im Format von BulkChange: nur angehakte Felder.
     *
     * @param  list<string>  $locked
     * @param  array<string, mixed>  $state
     * @return array<string, array{op: string, value: mixed}>
     */
    private static function changes(CustomTable $table, array $locked, array $state): array
    {
        $changes = [];

        foreach (BulkChange::editableFields($table, $locked) as $name => $field) {
            $row = (array) ($state[$name] ?? []);

            if (! ($row['on'] ?? false)) {
                continue;
            }

            $op = (string) ($row['op'] ?? (BulkChange::canSet($field) ? BulkChange::SET : BulkChange::CLEAR));
            $changes[$name] = ['op' => $op, 'value' => $op === BulkChange::SET ? ($row[$name] ?? null) : null];
        }

        return $changes;
    }

    /**
     * Daten der Vorschau (resources/views/filament/data/bulk-preview.blade.php).
     *
     * @internal öffentlich nur für Tests
     *
     * @param  list<string>  $locked
     * @param  array<string, mixed>  $state
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public static function previewData(CustomTable $table, array $locked, array $state, array $ids): array
    {
        $changes = self::changes($table, $locked, $state);
        $max = BulkRecordsWork::max();
        $data = [
            'count' => count($ids),
            'tooMany' => $max > 0 && count($ids) > $max,
            'max' => $max,
            'background' => count($ids) > 2 * BulkRecordsWork::BLOCK,
            'columns' => [],
            'rows' => [],
            'hints' => [],
            'empty' => $changes === [],
        ];

        if ($changes === [] || $data['tooMany']) {
            return $data;
        }

        $change = BulkChange::fromArray($table, $changes, $locked);
        $preview = BulkPreview::for($change, $ids);
        $fields = $table->fields->keyBy('name');
        $data['columns'] = array_map(static fn (string $name): string => (string) ($fields[$name]->label ?? $name), $change->fieldNames());
        $data['hints'] = $preview->hints;

        foreach ($preview->examples as $example) {
            $cells = [];

            foreach ($change->fieldNames() as $name) {
                /** @var CustomField $field */
                $field = $fields[$name];
                $cells[] = [
                    'before' => DynamicValueFormatter::display($field, $example['original']) ?? '—',
                    'after' => DynamicValueFormatter::display($field, $example['record']) ?? '—',
                ];
            }

            $data['rows'][] = ['key' => $example['record']->getKey(), 'cells' => $cells];
        }

        return $data;
    }

    private static function assertWithinLimit(int $count): void
    {
        if (BulkRecordsWork::max() > 0 && $count > BulkRecordsWork::max()) {
            Notification::make()->danger()->title(__('data.bulk.errors.too_many', ['count' => $count, 'max' => BulkRecordsWork::max()]))->send();

            throw new Halt;
        }
    }

    /** @return array{0: string, 1: string|null} erste Zeile als Titel, der Rest als Text */
    private static function split(string $message): array
    {
        $parts = explode("\n", $message, 2);

        return [$parts[0], isset($parts[1]) && trim($parts[1]) !== '' ? $parts[1] : null];
    }
}
