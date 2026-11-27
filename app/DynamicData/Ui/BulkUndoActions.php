<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\Apps\CurrentApp;
use App\DynamicData\Bulk\BulkCalc;
use App\DynamicData\Bulk\BulkChange;
use App\DynamicData\Bulk\BulkChangeAccess;
use App\DynamicData\Bulk\BulkUndo;
use App\DynamicData\Schema\FieldType;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\BulkChange as BulkChangeHead;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Support\AppLocale;
use App\Work\Types\BulkRecordsWork;
use App\Work\WorkService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

/**
 * „Letzte Sammeländerungen“ und „Rückgängig“ in der Datenansicht (B-133 S3, BULK_EDIT.md §13 Frage 10).
 *
 * Die Bestätigung „Rückgängig“ ist eine Aktion der Seite (undoBulkChange mit dem Argument head); Erfolgsmeldungen,
 * Detailtabellen und die Seite einer Arbeit verlinken sie über die Adresse der Datenansicht (undoUrl()), die Filament
 * beim Laden öffnet.
 */
final class BulkUndoActions
{
    public const string UNDO = 'undoBulkChange';

    public const string HISTORY = 'bulkHistory';

    /** Kopfaktion der Liste: Seitenfenster mit den letzten Sammelaktionen der Person (bzw. aller, siehe Rechte). */
    public static function history(CustomTable $table): Action
    {
        return Action::make(self::HISTORY)
            ->label(__('data.bulk.undo.history'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->visible(static fn (): bool => BulkChangeAccess::recent(self::user(), $table, 1)->isNotEmpty())
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->modalHeading(__('data.bulk.undo.history'))
            ->modalDescription(__('data.bulk.undo.history_help'))
            ->modalContent(static fn () => view('filament.data.bulk-history', [
                'rows' => BulkChangeAccess::recent(self::user(), $table)->map(static fn (BulkChangeHead $head): array => [
                    'id' => $head->id,
                    'when' => AppLocale::dateTime($head->created_at) ?? '—',
                    'who' => $head->user->name ?? '—',
                    'what' => self::describe($head, $table),
                    'count' => $head->count,
                    'state' => self::state($head),
                    'undoable' => BulkChangeAccess::canUndo(self::user(), $head),
                    'records' => DynamicDataPage::urlFor($table->name, bulk: $head->id),
                ])->all(),
                'action' => self::UNDO,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('data.bulk.undo.close'))
            // Die Bestätigung öffnet aus dem Seitenfenster heraus; Filament sucht sie unter dessen Aktionen.
            ->registerModalActions([self::undo($table)]);
    }

    /** Bestätigung und Start der Rücknahme (Arbeit bulk_records mit undo_of). */
    public static function undo(CustomTable $table): Action
    {
        $head = static fn (array $arguments): ?BulkChangeHead => BulkChangeHead::query()
            ->where('custom_table_id', $table->id)
            ->with(['user', 'undoneBy', 'custom_table'])
            ->find((int) ($arguments['head'] ?? 0));

        // Vorschau einmal je Kopf und Anfrage (Inhalt, Knopf und Sperre fragen sie ab; die Aktion entsteht je Anfrage neu).
        $cache = [];
        $preview = static function (array $arguments) use ($head, $table, &$cache): ?array {
            $key = (int) ($arguments['head'] ?? 0);

            if (! array_key_exists($key, $cache)) {
                $found = $head($arguments);
                $cache[$key] = $found === null ? null : self::previewData(new BulkUndo($found, $table));
            }

            return $cache[$key];
        };

        return Action::make(self::UNDO)
            ->label(__('data.bulk.undo.action'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->authorize(static fn (array $arguments): bool => ($found = $head($arguments)) !== null && BulkChangeAccess::canUndo(self::user(), $found))
            ->modalIcon('heroicon-o-arrow-uturn-left')
            ->modalHeading(__('data.bulk.undo.heading'))
            ->modalContent(static fn (array $arguments) => ($found = $head($arguments)) === null ? null : view('filament.data.bulk-undo-confirm', [
                'what' => self::describe($found, $table),
                'who' => $found->user->name ?? '—',
                'when' => AppLocale::dateTime($found->created_at) ?? '—',
                'count' => $found->count,
                'hints' => self::hints($found, $table),
                'preview' => $preview($arguments),
                'records' => DynamicDataPage::urlFor($table->name, bulk: $found->id),
            ]))
            // Knopf mit der Zahl, die zurückkommt; gesperrt, wenn nachweislich keiner (Vorschau über alle).
            ->modalSubmitActionLabel(static function (array $arguments) use ($preview): string {
                $data = $preview($arguments);
                $count = $data === null ? 0 : ($data['partial'] ? $data['total'] : $data['counts'][BulkUndo::REVERT]);

                return trans_choice('data.bulk.undo.submit', $count, ['count' => $count]);
            })
            ->modalSubmitAction(static function (Action $action, array $arguments) use ($preview): Action {
                $data = $preview($arguments);

                return $action->disabled($data !== null && ! $data['partial'] && $data['counts'][BulkUndo::REVERT] === 0);
            })
            ->modalWidth(Width::TwoExtraLarge)
            // Aus der Liste geöffnet: danach auch das Seitenfenster schliessen (die Liste wäre veraltet).
            ->cancelParentActions()
            ->action(static function (array $arguments, mixed $livewire) use ($table, $head): void {
                $found = $head($arguments);
                $user = self::user();

                if ($found === null || ! BulkChangeAccess::canUndo($user, $found)) {
                    Notification::make()->danger()->title(__('work.bulk.undo.not_allowed'))->send();

                    return;
                }

                $item = app(WorkService::class)->startAndAdvance(BulkRecordsWork::KEY, [
                    'table_id' => $table->id,
                    'changes' => (array) $found->field_changes,
                    'locked' => [],
                    'record_ids' => $found->recordIds(),
                    'dry_run' => false,
                    'undo_of' => $found->id,
                ], $user, app(CurrentApp::class)->id($user));

                if ($item->isOpen()) {
                    $livewire->redirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));

                    return;
                }

                $lines = explode("\n", (string) $item->message, 2);
                $notification = Notification::make()->title($lines[0])->body(isset($lines[1]) ? $lines[1] : null);
                $item->status === WorkItem::DONE
                    ? $notification->{($item->result['skipped'] ?? 0) > 0 ? 'warning' : 'success'}()->persistent()->send()
                    : $notification->danger()->persistent()->send();

                $livewire->dispatch(DynamicRecordActions::REFRESH_EVENT);
            });
    }

    /**
     * Vorschau der Rücknahme für die Bestätigung: Zählungen und Beispiele „Datensatz · Feld · jetzt → danach“; bleibende
     * Datensätze mit Grund.
     *
     * @internal öffentlich nur für Tests
     *
     * @return array{counts: array<string, int>, total: int, partial: bool, scan: int, columns: list<string>, rows: list<array{title: string, status: string, cells: list<array{now: string, after: string}>}>, operation: string}
     */
    public static function previewData(BulkUndo $undo): array
    {
        $preview = $undo->preview();
        $fields = $undo->table->fields->keyBy('name');
        $names = $undo->head->operation === BulkChangeHead::UPDATE ? $undo->fields() : [];
        $rows = [];

        foreach ($preview['examples'] as $example) {
            $record = $example['record'];
            $cells = [];

            if ($record !== null) {
                $after = clone $record;
                $after->forceFill($example['values']);

                foreach ($names as $name) {
                    /** @var CustomField $field */
                    $field = $fields[$name];
                    $cells[] = [
                        'now' => DynamicValueFormatter::display($field, $record) ?? '—',
                        'after' => DynamicValueFormatter::display($field, $after) ?? '—',
                    ];
                }
            }

            $rows[] = [
                'title' => $record === null ? '#'.$example['id'] : BulkRecordsWork::title($undo->table, $record),
                'status' => $example['status'],
                'cells' => $cells,
            ];
        }

        return [
            'counts' => $preview['counts'],
            'total' => $preview['total'],
            'partial' => $preview['partial'],
            'scan' => BulkUndo::SCAN,
            'columns' => array_map(static fn (string $name): string => (string) ($fields[$name]->label ?? $name), $names),
            'rows' => $rows,
            'operation' => $undo->head->operation,
        ];
    }

    /** Adresse der Datenansicht, die die Bestätigung „Rückgängig“ für den Kopf öffnet. */
    public static function undoUrl(BulkChangeHead $head): string
    {
        return DynamicDataPage::getUrl([
            'table' => $head->custom_table?->name,
            'action' => self::UNDO,
            'actionArguments' => ['head' => $head->id],
        ]);
    }

    /** Link „Rückgängig“ für eine Erfolgsmeldung, wenn die Person die Sammelaktion zurücknehmen darf. */
    public static function notificationAction(?BulkChangeHead $head): ?Action
    {
        if ($head === null || ! BulkChangeAccess::canUndo(self::user(), $head)) {
            return null;
        }

        return Action::make('undo')->label(__('data.bulk.undo.action'))->link()->url(self::undoUrl($head));
    }

    /**
     * Was die Sammelaktion getan hat, z. B. „Tarif: um 5 %, gerundet auf ein Vielfaches von 0.05“ oder „12 gelöscht“;
     * eine Rücknahme mit Datum der zurückgenommenen.
     */
    public static function describe(BulkChangeHead $head, CustomTable $table): string
    {
        $text = match ($head->operation) {
            BulkChangeHead::DELETE => __('data.bulk.undo.deleted'),
            BulkChangeHead::RESTORE => __('data.bulk.undo.restored'),
            default => self::fieldsText($head, $table),
        };

        if ($head->undo_of_id !== null) {
            $original = $head->undoOf;
            $text = __('data.bulk.undo.undo_of', ['when' => $original === null ? '—' : (AppLocale::dateTime($original->created_at) ?? '—')]).': '.$text;
        }

        return $text;
    }

    /** Stand: zurückgenommen (ganz oder teilweise) am … von …; null = offen. */
    public static function state(BulkChangeHead $head): ?string
    {
        $undo = $head->undoneBy;

        if ($undo === null) {
            return null;
        }

        $parameters = ['when' => AppLocale::dateTime($undo->created_at) ?? '—', 'who' => $undo->user->name ?? '—', 'count' => (int) (($undo->result ?? [])['skipped'] ?? 0)];

        return BulkChangeAccess::partial($undo)
            ? trans_choice('data.bulk.undo.state_partial', $parameters['count'], $parameters)
            : __('data.bulk.undo.state_done', $parameters);
    }

    /** @return list<string> Hinweise in der Bestätigung */
    public static function hints(BulkChangeHead $head, CustomTable $table): array
    {
        $hints = [];
        $later = BulkChangeHead::query()
            ->where('custom_table_id', $table->id)
            ->where('id', '>', $head->id)
            ->whereNull('undone_by_id')
            ->where(static fn ($query) => $query->whereNull('undo_of_id')->orWhere('undo_of_id', '!=', $head->id))
            ->count();

        if ($later > 0) {
            $hints[] = trans_choice('data.bulk.undo.hint_later', $later, ['count' => $later]);
        }

        if ($head->undone_by_id !== null) {
            $hints[] = __('data.bulk.undo.hint_again');
        }

        if ($head->operation === BulkChangeHead::UPDATE) {
            $dropped = (new BulkUndo($head, $table))->droppedFields();

            if ($dropped !== []) {
                $hints[] = __('work.bulk.undo.dropped', ['fields' => implode(', ', $dropped)]);
            }

            $hints[] = __('data.bulk.undo.hint_changed');

            if ($table->fields->contains(static fn (CustomField $f): bool => $f->type === FieldType::Number && filled($f->options['assign_when'] ?? null))) {
                $hints[] = __('data.bulk.undo.hint_numbers');
            }
        }

        $hints[] = __('data.bulk.undo.hint_effects');

        return $hints;
    }

    private static function fieldsText(BulkChangeHead $head, CustomTable $table): string
    {
        $fields = $table->fields->keyBy('name');
        $parts = [];

        foreach ((array) $head->field_changes as $name => $change) {
            /** @var CustomField|null $field */
            $field = $fields[$name] ?? null;
            $label = $field->label ?? (string) $name;
            $change = (array) $change;
            $parts[] = $label.': '.match ($change['op'] ?? BulkChange::SET) {
                BulkChange::CLEAR => __('data.bulk.undo.ops.clear'),
                BulkChange::CALC => self::calcText($change),
                BulkChange::REPLACE => __('data.bulk.undo.ops.replace', ['search' => (string) ($change['search'] ?? ''), 'replace' => (string) ($change['replace'] ?? '')]),
                BulkChange::FORMULA => __('data.bulk.undo.ops.formula', ['formula' => (string) ($change['formula'] ?? '')]),
                default => __('data.bulk.undo.ops.set', ['value' => self::valueText($table, $field, $change['value'] ?? null)]),
            };
        }

        return Str::limit(implode(' · ', $parts), 300);
    }

    /** @param  array<string, mixed>  $change */
    private static function calcText(array $change): string
    {
        $operator = (string) ($change['operator'] ?? '');
        $text = __('data.bulk.calc.operators.'.$operator).' '.($change['operand'] ?? '').($operator === 'percent' ? ' %' : '');

        return match ($change['round_mode'] ?? null) {
            BulkCalc::ROUND_DIGITS => $text.', '.__('data.bulk.undo.ops.round_digits', ['value' => $change['round_value'] ?? '']),
            BulkCalc::ROUND_MULTIPLE => $text.', '.__('data.bulk.undo.ops.round_multiple', ['value' => $change['round_value'] ?? '']),
            default => $text,
        };
    }

    /** Gesetzter Wert in Anzeigeform (Auswahl mit Bezeichnung, Verknüpfung mit Anzeigewert, Ja/Nein). */
    private static function valueText(CustomTable $table, ?CustomField $field, mixed $value): string
    {
        if ($field === null || $value === null || $value === '') {
            return '—';
        }

        $probe = DynamicRecord::forTable($table)->newInstance();
        $probe->setAttribute($field->name, $value);

        return DynamicValueFormatter::display($field, $probe) ?? (is_scalar($value) ? (string) $value : '—');
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
