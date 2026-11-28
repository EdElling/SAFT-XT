<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\History\HistoryUndo;
use App\DynamicData\Validation\RecordChecker;
use App\DynamicData\Write\RecordVersion;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\StaleRecordException;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\ActivityChanges;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Support\AppLocale;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;

/**
 * „Rückgängig“ je Eintrag im Verlauf eines Datensatzes (B-173, HISTORY.md §4). Die Bestätigung ist eine verschachtelte
 * Aktion des Verlaufsdialogs (registerModalActions()) mit dem Argument entry; je Feld ein Häkchen „Feld: jetzt → zurück
 * auf“. Gespeichert wird als gewöhnliches Bearbeiten: Prüfungen wie beim Erfassen, RecordWriter mit der Version beim
 * Öffnen und WriteOptions::reverting().
 */
final class HistoryUndoActions
{
    public const string UNDO = 'undoHistoryEntry';

    private const string FIELDS = 'fields';

    private const string VERSION = '_version';

    public static function undo(CustomTable $table): Action
    {
        // Einordnung einmal je Eintrag und Anfrage (Recht, Inhalt und Speichern fragen sie ab).
        $cache = [];
        $find = static function (array $arguments, ?DynamicRecord $record) use ($table, &$cache): ?HistoryUndo {
            $entry = (int) ($arguments['entry'] ?? 0);

            if ($record === null || $entry === 0) {
                return null;
            }

            if (! array_key_exists($entry, $cache)) {
                // Heutiger Stand, nicht der beim Öffnen der Liste geladene.
                $current = $record->newQuery()->withTrashed()->find($record->getKey());
                $cache[$entry] = $current instanceof DynamicRecord ? HistoryUndo::of($entry, $table, $current) : null;
            }

            return $cache[$entry];
        };

        return Action::make(self::UNDO)
            ->label(__('data.history.undo.action'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->authorize(static fn (array $arguments, ?DynamicRecord $record): bool => self::possible($find($arguments, $record), $table))
            ->modalIcon('heroicon-o-arrow-uturn-left')
            ->modalHeading(__('data.history.undo.heading'))
            ->modalDescription(static function (array $arguments, ?DynamicRecord $record) use ($find): ?string {
                $undo = $find($arguments, $record);

                return $undo === null ? null : __('data.history.undo.description', [
                    'when' => AppLocale::dateTime($undo->entry->created_at) ?? '—',
                    'who' => ActivityChanges::causerLabel($undo->entry),
                ]);
            })
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel(__('data.history.undo.submit'))
            ->fillForm(static fn (array $arguments, ?DynamicRecord $record): array => ($undo = $find($arguments, $record)) === null ? [] : [
                self::FIELDS => $undo->preselected(),
                // Stand beim Öffnen: hat inzwischen jemand gespeichert, stimmen die angezeigten Werte nicht mehr.
                self::VERSION => RecordVersion::of($undo->record),
            ])
            ->schema(static function (array $arguments, ?DynamicRecord $record) use ($find): array {
                $rows = collect($find($arguments, $record)?->fields() ?? [])->keyBy('name');

                return [
                    CheckboxList::make(self::FIELDS)
                        ->hiddenLabel()
                        ->options($rows->map(static fn (array $row): string => __('data.history.undo.line', ['field' => $row['label'], 'now' => $row['now'], 'back' => $row['back']]))->all())
                        ->descriptions($rows->map(static fn (array $row): ?string => self::note($row))->filter()->all())
                        ->disableOptionWhen(static fn (string $value): bool => in_array($rows->get($value)['status'] ?? HistoryUndo::FIXED, [HistoryUndo::ALREADY, HistoryUndo::FIXED], true))
                        ->required()
                        ->validationMessages(['required' => __('data.history.undo.none')]),
                    Hidden::make(self::VERSION),
                ];
            })
            ->action(static function (array $data, array $arguments, ?DynamicRecord $record, mixed $livewire) use ($find, $table): void {
                $undo = $find($arguments, $record);

                if (! self::possible($undo, $table)) {
                    Notification::make()->danger()->title(__('data.history.undo.not_possible'))->send();

                    return;
                }

                $values = $undo->values(array_values(array_map(strval(...), (array) ($data[self::FIELDS] ?? []))));

                if ($values === []) {
                    Notification::make()->danger()->title(__('data.history.undo.none'))->send();

                    throw new Halt;
                }

                // Prüfungen wie beim Erfassen (Pflicht, eindeutig, Auswahlwerte, Verknüpfungen, Regeln der Tabelle).
                $problems = app(RecordChecker::class)->check($table, $values, $undo->record, references: true);

                if ($problems !== []) {
                    Notification::make()->danger()->persistent()->title(__('data.history.undo.failed'))
                        ->body(implode("\n", array_map(static fn (array $problem): string => ($problem[0] !== null ? $problem[0]->label.': ' : '').$problem[1], $problems)))
                        ->send();

                    throw new Halt;
                }

                $options = WriteOptions::ui()->expecting(isset($data[self::VERSION]) ? (string) $data[self::VERSION] : null)->reverting((int) $undo->entry->getKey());

                try {
                    app(RecordWriter::class)->update($undo->record, $values, $options);
                } catch (StaleRecordException) {
                    // Ohne „Trotzdem speichern“: Die Bestätigung zeigt Werte, die nicht mehr stimmen; der Verlauf darunter
                    // zeigt den neuen Stand.
                    Notification::make()->danger()->persistent()->title(__('errors.titles.stale_record'))->body(__('data.history.undo.stale'))->send();

                    return;
                } catch (BusinessRuleException $exception) {
                    Notification::make()->danger()->persistent()->title(__('data.history.undo.failed'))->body($exception->getMessage())->send();

                    throw new Halt;
                }

                Notification::make()->success()->title(trans_choice('data.history.undo.done', count($values), ['count' => count($values)]))->send();
                $livewire->dispatch(DynamicRecordActions::REFRESH_EVENT);
            });
    }

    /** Symbol und Bestätigung: Recht „ändern“, Tabelle erlaubt Bearbeiten, Eintrag „Geändert“ mit zurücknehmbarem Feld. */
    public static function possible(?HistoryUndo $undo, CustomTable $table): bool
    {
        return $undo !== null && HistoryUndo::allowed($table, $undo->record) && $undo->offered();
    }

    /** @param  array{status: string, reason: string|null}  $row */
    private static function note(array $row): ?string
    {
        return match ($row['status']) {
            HistoryUndo::CHANGED => __('data.history.undo.changed'),
            HistoryUndo::ALREADY => __('data.history.undo.already'),
            HistoryUndo::FIXED => __('data.history.undo.reasons.'.$row['reason']),
            default => null,
        };
    }
}
