<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\DynamicData\Bulk\BulkChange;
use App\DynamicData\Bulk\BulkChangeAccess;
use App\DynamicData\Bulk\BulkUndo;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Validation\RecordChecker;
use App\DynamicData\Write\RecordVersion;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\StaleRecordException;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\BulkChange as BulkChangeHead;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Work\CleansUp;
use App\Work\DescribesProgress;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Arbeit „Sammeländerung“ (B-133, BULK_EDIT.md §6): ausgewählte Datensätze einer Tabelle gemeinsam ändern, alles oder
 * nichts. Je Datensatz über den RecordWriter mit Herkunft „bulk“ und der Kennung „bulk:<uuid>“ im Protokoll.
 *
 * 1. Prüfphase (Blöcke zu BLOCK): je Datensatz die Prüfungen wie beim Erfassen (RecordChecker), dann alle Datensätze des
 *    Blocks im Probelauf wirklich speichern (Phasen, Plugin-Listener, Nummernkreise) und zurückrollen. Gespeichert wird
 *    die Version je Datensatz; unveränderte zählen nur. Fehler werden gesammelt, die Prüfung läuft bis zum Ende.
 * 2. Mit Fehlern: Arbeit schlägt fehl, nichts ist geändert. Mit „dry_run“: fertig nach der Prüfung.
 * 3. Schreibphase (Blöcke zu BLOCK, je Block eine Transaktion samt Fortschritt): speichern mit der Version aus der
 *    Prüfphase (optimistische Sperre); vorher die alten Werte ins Journal. Ein inzwischen geänderter Datensatz oder ein
 *    neuer Fehler beendet die Arbeit, abort() schreibt die alten Werte der fertigen Blöcke zurück.
 *
 * Nach Erfolg hält ein Kopf (App\Models\BulkChange) Kennung, Person, Art und die geänderten Schlüssel fest, für
 * „Letzte Sammeländerungen“ und „Rückgängig“ (S3, BULK_EDIT.md §13).
 *
 * Rücknahme (Parameter undo_of = Kopf): gleiche Phasen, aber Datensätze, die seither geändert wurden, schon zurück sind
 * oder die Prüfung nicht bestehen, werden übersprungen und gemeldet statt die Arbeit scheitern zu lassen (BulkUndo).
 *
 * Parameter: table_id, changes (BulkChange::toArray()), locked (gesperrte Felder), record_ids, dry_run; undo_of.
 */
final readonly class BulkRecordsWork implements CleansUp, DescribesProgress, WorkType, WritesRecords
{
    public const string KEY = 'bulk_records';

    public const string RUN_PREFIX = 'bulk:';

    public const int BLOCK = 100;

    /** So viele Fehler nennt die Meldung, gezählt werden alle. */
    public const int MAX_ERRORS = 20;

    private const string CHECK = 'check';

    private const string WRITE = 'write';

    /** Neue Werte eindeutiger Felder über alle Blöcke der Prüfphase: Feld => Wert (klein) => Schlüssel. */
    private const string UNIQUE_FILE = 'unique.json';

    public function __construct(private RecordWriter $writer, private RecordChecker $checker) {}

    /** Obergrenze je Sammeländerung (dynamic_data.bulk_max); 0 = ohne Grenze. */
    public static function max(): int
    {
        return max(0, (int) config('dynamic_data.bulk_max', 5000));
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.bulk_records');
    }

    public function describe(array $parameters): string
    {
        $table = CustomTable::query()->find((int) ($parameters['table_id'] ?? 0));
        $fields = implode(', ', array_keys((array) ($parameters['changes'] ?? [])));
        $undo = isset($parameters['undo_of']) ? __('work.bulk.undo.label').' ' : '';

        return ($table === null ? '–' : $table->name).': '.$undo.($fields === '' ? '' : $fields);
    }

    public function prepare(array $parameters): WorkPlan
    {
        $count = count(self::ids($parameters));

        return new WorkPlan(
            ['run' => self::RUN_PREFIX.Str::uuid(), 'count' => $count, 'phase' => self::CHECK, 'index' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'changed' => 0],
            ($parameters['dry_run'] ?? false) ? $count : 2 * $count,
        );
    }

    public function progress(array $state, int $done, ?int $total): ?string
    {
        if ($state === []) {
            return null;
        }

        return __(($state['phase'] ?? self::CHECK) === self::CHECK ? 'work.bulk.checking' : 'work.bulk.writing', [
            'done' => number_format((int) ($state['index'] ?? 0), 0, '.', "'"),
            'total' => number_format((int) ($state['count'] ?? 0), 0, '.', "'"),
        ]);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $table = CustomTable::query()->with('fields')->find((int) ($step->parameters['table_id'] ?? 0))
            ?? throw new BusinessRuleException(__('work.bulk.table_missing'), 'bulk.table_missing');

        if (isset($step->parameters['undo_of'])) {
            $undo = BulkUndo::of((int) $step->parameters['undo_of'], $table);
            $user = auth()->user();

            if (! BulkChangeAccess::canUndo($user instanceof User ? $user : null, $undo->head)) {
                throw new BusinessRuleException(__('work.bulk.undo.not_allowed'), 'bulk.undo_not_allowed');
            }

            return ($step->state['phase'] ?? self::CHECK) === self::WRITE
                ? $this->undoWrite($step, $undo)
                : $this->undoCheck($step, $undo);
        }

        if (! BulkChange::allowed($table)) {
            throw new BusinessRuleException(__('data.bulk.errors.not_allowed'), 'bulk.not_allowed');
        }

        $change = BulkChange::fromArray($table, (array) ($step->parameters['changes'] ?? []), (array) ($step->parameters['locked'] ?? []));
        $change->validate();

        return ($step->state['phase'] ?? self::CHECK) === self::WRITE
            ? $this->write($step, $change)
            : $this->check($step, $change);
    }

    /**
     * Rücknahme nach Fehler oder Abbruch in der Schreibphase: die alten Werte der committeten Blöcke zurückschreiben,
     * rückwärts, ohne Plugin-Ereignisse (eine Geschäftsregel darf das Zurücknehmen nicht verhindern), mit Protokoll und
     * derselben Kennung. Datensätze, die inzwischen jemand anders geändert hat, bleiben und stehen im Systemprotokoll.
     */
    public function abort(WorkStep $step, string $status, string $message): void
    {
        if (($step->state['phase'] ?? self::CHECK) !== self::WRITE) {
            return;
        }

        $table = CustomTable::query()->with('fields')->find((int) ($step->parameters['table_id'] ?? 0));
        $blocks = intdiv((int) ($step->state['index'] ?? 0), self::BLOCK);

        if ($table === null || $blocks === 0) {
            return;
        }

        $options = WriteOptions::bulk((string) $step->state['run'])->withoutPluginEvents();

        for ($block = $blocks - 1; $block >= 0; $block--) {
            $entries = array_reverse(self::readJson($step, "journal-{$block}.json"));

            $this->writer->batch(function () use ($table, $entries, $options, $step): void {
                foreach ($entries as $entry) {
                    $record = DynamicRecord::forTable($table)->newQuery()->withTrashed()->find($entry['id']);

                    // Rücknahme einer gelöschten bzw. wiederhergestellten Sammelaktion: Gegenteil ausführen.
                    if (isset($entry['op']) && $record !== null) {
                        match (true) {
                            $entry['op'] === BulkChangeHead::RESTORE && ! $record->trashed() => $this->writer->delete($record, $options),
                            $entry['op'] === BulkChangeHead::DELETE && $record->trashed() => $this->writer->restore($record, $options),
                            default => null,
                        };

                        continue;
                    }

                    if ($record === null || $record->trashed() || ! self::sameValues($table, $record, (array) $entry['new'])) {
                        Log::warning('Sammeländerung: Datensatz nicht zurückgenommen, inzwischen geändert', ['table' => $table->name, 'id' => $entry['id'], 'run' => $step->state['run'] ?? null]);

                        continue;
                    }

                    $this->writer->update($record, (array) $entry['old'], $options);
                }
            }, $options);
        }
    }

    private function check(WorkStep $step, BulkChange $change): StepOutcome
    {
        $state = $step->state;
        $ids = self::ids($step->parameters);
        $chunk = array_slice($ids, (int) $state['index'], self::BLOCK);
        $records = $this->load($change->table, $chunk);
        $errors = (array) $state['errors'];
        $failed = (int) $state['failed'];
        $unchanged = (int) $state['unchanged'];
        $skipped = (int) ($state['skipped'] ?? 0);
        $versions = [];
        $options = WriteOptions::bulk((string) $state['run']);
        $unique = self::readJson($step, self::UNIQUE_FILE);
        $uniqueFields = $change->table->fields->filter(static fn (CustomField $f): bool => $f->is_unique)->keyBy('name');
        $fail = static function (string $message) use (&$errors, &$failed): void {
            $failed++;

            if (count($errors) < self::MAX_ERRORS) {
                $errors[] = $message;
            }
        };

        $this->writer->trial(function () use ($chunk, $records, $change, $options, $fail, $uniqueFields, &$versions, &$unchanged, &$skipped, &$unique): void {
            foreach ($chunk as $id) {
                $record = $records->get($id);

                if ($record === null) {
                    $fail(__('work.bulk.record_missing', ['record' => '#'.$id]));

                    continue;
                }

                $version = RecordVersion::of($record);
                $resolved = $change->resolve($record);

                if ($resolved['errors'] !== []) {
                    $fail(self::title($change->table, $record).': '.implode(' · ', $change->errorTexts($resolved['errors'])));

                    continue;
                }

                $values = $resolved['values'];

                // Nichts zu ändern (kein bisheriger Wert, kein Treffer): auch nicht prüfen.
                if ($values === []) {
                    $skipped++;

                    continue;
                }

                $problems = $this->checker->check($change->table, $values, $record, references: true);

                // Eindeutige Felder (ersetzen, rechnen, Formel): derselbe neue Wert bei zwei ausgewählten Datensätzen,
                // über alle Blöcke (der Probelauf rollt je Block zurück, die Datenbank sähe das nicht).
                foreach (array_intersect_key($values, $uniqueFields->all()) as $name => $value) {
                    $key = mb_strtolower((string) $value);

                    if ($value === null || $value === '' || (string) $record->getRawOriginal($name) === (string) $value) {
                        continue;
                    }

                    if (isset($unique[$name][$key]) && $unique[$name][$key] !== (string) $record->getKey()) {
                        $problems[] = [$uniqueFields[$name], __('work.bulk.duplicate_in_selection', ['value' => $value, 'record' => '#'.$unique[$name][$key]])];
                    }

                    $unique[$name][$key] ??= (string) $record->getKey();
                }

                if ($problems !== []) {
                    $fail(self::title($change->table, $record).': '.implode(' · ', array_map(
                        static fn (array $problem): string => ($problem[0] === null ? '' : $problem[0]->label.': ').$problem[1],
                        $problems,
                    )));

                    continue;
                }

                if (! $change->apply($record, $values)) {
                    $resolved['skipped'] === [] ? $unchanged++ : $skipped++;

                    continue;
                }

                try {
                    // Eigener Sicherungspunkt: Ein gescheiterter Datensatz hinterlässt nichts, die übrigen prüfen weiter.
                    $this->writer->savepoint(fn () => $this->writer->save($record, $options));
                    $versions[(string) $record->getKey()] = $version;
                } catch (BusinessRuleException $e) {
                    $fail(self::title($change->table, $record).': '.$e->getMessage());
                } catch (QueryException $e) {
                    report($e);
                    $fail(self::title($change->table, $record).': '.__('work.bulk.database_error'));
                }
            }
        }, $options);

        self::writeJson($step, 'versions-'.intdiv((int) $state['index'], self::BLOCK).'.json', $versions);

        if ($uniqueFields->isNotEmpty()) {
            self::writeJson($step, self::UNIQUE_FILE, $unique);
        }

        $index = (int) $state['index'] + count($chunk);
        $state = [...$state, 'index' => $index, 'errors' => $errors, 'failed' => $failed, 'unchanged' => $unchanged, 'skipped' => $skipped];

        if ($index < count($ids)) {
            return StepOutcome::continue($state, count($chunk));
        }

        if ($failed > 0) {
            throw new BusinessRuleException(
                trans_choice('work.bulk.check_failed', $failed, ['count' => $failed])."\n".implode("\n", $errors).($failed > count($errors) ? "\n…" : ''),
                'bulk.check_failed',
            );
        }

        $changing = count($ids) - $unchanged - $skipped;

        if ($step->parameters['dry_run'] ?? false) {
            return StepOutcome::finished(
                trans_choice('work.bulk.check_passed', $changing, ['count' => $changing]).self::unchangedText($unchanged, $skipped),
                ['changing' => $changing, 'unchanged' => $unchanged, 'skipped' => $skipped, 'dry_run' => true],
                count($chunk),
            );
        }

        return StepOutcome::continue([...$state, 'phase' => self::WRITE, 'index' => 0, 'unchanged' => 0, 'skipped' => 0], count($chunk));
    }

    private function write(WorkStep $step, BulkChange $change): StepOutcome
    {
        $state = $step->state;
        $ids = self::ids($step->parameters);
        $block = intdiv((int) $state['index'], self::BLOCK);
        $chunk = array_slice($ids, (int) $state['index'], self::BLOCK);
        $records = $this->load($change->table, $chunk);
        $versions = self::readJson($step, "versions-{$block}.json");
        $options = WriteOptions::bulk((string) $state['run']);
        $unchanged = (int) $state['unchanged'];
        $skipped = (int) ($state['skipped'] ?? 0);
        $changed = (int) $state['changed'];
        $journal = [];
        $fields = $change->fieldNames();

        foreach ($chunk as $id) {
            $record = $records->get($id) ?? throw new BusinessRuleException(__('work.bulk.changed_meanwhile', ['record' => '#'.$id]), 'bulk.changed_meanwhile');
            $version = $versions[(string) $id] ?? null;
            $old = array_intersect_key($record->getAttributes(), array_flip($fields));
            $resolved = $change->resolve($record);

            if ($resolved['errors'] !== []) {
                throw new BusinessRuleException(__('work.bulk.write_failed', ['record' => self::title($change->table, $record), 'message' => implode(' · ', $change->errorTexts($resolved['errors']))]), 'bulk.write_failed');
            }

            if (! $change->apply($record, $resolved['values'])) {
                $resolved['skipped'] === [] ? $unchanged++ : $skipped++;

                continue;
            }

            // In der Prüfphase schon so, jetzt nicht mehr: jemand hat inzwischen gespeichert.
            if ($version === null) {
                throw new BusinessRuleException(__('work.bulk.changed_meanwhile', ['record' => self::title($change->table, $record)]), 'bulk.changed_meanwhile');
            }

            try {
                $this->writer->save($record, $options->expecting((string) $version));
            } catch (StaleRecordException) {
                throw new BusinessRuleException(__('work.bulk.changed_meanwhile', ['record' => self::title($change->table, $record)]), 'bulk.changed_meanwhile');
            } catch (BusinessRuleException $e) {
                throw new BusinessRuleException(__('work.bulk.write_failed', ['record' => self::title($change->table, $record), 'message' => $e->getMessage()]), 'bulk.write_failed');
            }

            $journal[] = ['id' => $record->getKey(), 'old' => $old, 'new' => array_intersect_key($record->getAttributes(), array_flip($fields))];
            $changed++;
        }

        // Vor dem Commit festhalten, was abort() zurücknehmen müsste; ein zurückgerollter Block wird nie gelesen.
        self::writeJson($step, "journal-{$block}.json", $journal);
        $index = (int) $state['index'] + count($chunk);
        $state = [...$state, 'index' => $index, 'unchanged' => $unchanged, 'skipped' => $skipped, 'changed' => $changed];

        if ($index < count($ids)) {
            return StepOutcome::continue($state, count($chunk));
        }

        if ($changed > 0) {
            BulkChangeHead::recordRun((string) $state['run'], $change->table, BulkChangeHead::UPDATE, self::journalIds($step, $block), $change->toArray());
        }

        return StepOutcome::finished(
            trans_choice('work.bulk.done', $changed, ['count' => $changed]).self::unchangedText($unchanged, $skipped),
            ['changed' => $changed, 'unchanged' => $unchanged, 'skipped' => $skipped, 'run' => $state['run']],
            count($chunk),
        );
    }

    /**
     * Prüfphase der Rücknahme: je Datensatz einordnen (BulkUndo); zurückzunehmende im Probelauf speichern bzw. löschen
     * oder wiederherstellen. Wer die Prüfung nicht besteht, wird mit Grund gemerkt und in der Schreibphase übersprungen.
     */
    private function undoCheck(WorkStep $step, BulkUndo $undo): StepOutcome
    {
        $state = $step->state;
        $ids = self::ids($step->parameters);
        $block = intdiv((int) $state['index'], self::BLOCK);
        $chunk = array_slice($ids, (int) $state['index'], self::BLOCK);
        $records = $this->load($undo->table, $chunk, trashed: true);
        $logged = $undo->logged($chunk);
        $options = WriteOptions::bulk((string) $state['run']);
        $errors = (array) $state['errors'];
        $failed = (int) $state['failed'];
        $versions = [];

        $this->writer->trial(function () use ($chunk, $records, $logged, $undo, $options, &$versions, &$errors, &$failed): void {
            foreach ($chunk as $id) {
                $record = $records->get($id);
                [$status, $values] = $undo->classify($record, $logged[(string) $id] ?? null);

                if ($status !== BulkUndo::REVERT || $record === null) {
                    continue;
                }

                $problems = $undo->head->operation === BulkChangeHead::UPDATE ? $this->checker->check($undo->table, $values, $record, references: true) : [];
                $message = $problems === [] ? null : implode(' · ', array_map(
                    static fn (array $problem): string => ($problem[0] === null ? '' : $problem[0]->label.': ').$problem[1],
                    $problems,
                ));
                $version = RecordVersion::of($record);

                if ($message === null) {
                    try {
                        $this->writer->savepoint(fn () => $this->revert($undo, $record, $values, $options));
                    } catch (BusinessRuleException $e) {
                        $message = $e->getMessage();
                    } catch (QueryException $e) {
                        report($e);
                        $message = __('work.bulk.database_error');
                    }
                }

                if ($message !== null) {
                    $failed++;
                    $versions[(string) $id] = false;

                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = self::title($undo->table, $record).': '.$message;
                    }

                    continue;
                }

                $versions[(string) $id] = $version;
            }
        }, $options);

        self::writeJson($step, "versions-{$block}.json", $versions);
        $index = (int) $state['index'] + count($chunk);
        $state = [...$state, 'index' => $index, 'errors' => $errors, 'failed' => $failed];

        return $index < count($ids)
            ? StepOutcome::continue($state, count($chunk))
            : StepOutcome::continue([...$state, 'phase' => self::WRITE, 'index' => 0, 'counts' => []], count($chunk));
    }

    /**
     * Schreibphase der Rücknahme: erneut einordnen (inzwischen geändert?), dann mit der Version aus der Prüfphase
     * zurückschreiben bzw. löschen oder wiederherstellen; je Datensatz ein Sicherungspunkt, damit ein Fehler nur ihn
     * überspringt. Am Ende Kopf der Rücknahme anlegen und am zurückgenommenen Kopf vermerken.
     */
    private function undoWrite(WorkStep $step, BulkUndo $undo): StepOutcome
    {
        $state = $step->state;
        $ids = self::ids($step->parameters);
        $block = intdiv((int) $state['index'], self::BLOCK);
        $chunk = array_slice($ids, (int) $state['index'], self::BLOCK);
        $records = $this->load($undo->table, $chunk, trashed: true);
        $logged = $undo->logged($chunk);
        $versions = self::readJson($step, "versions-{$block}.json");
        $options = WriteOptions::bulk((string) $state['run']);
        $counts = (array) ($state['counts'] ?? []);
        $errors = (array) $state['errors'];
        $failed = (int) $state['failed'];
        $journal = [];
        $fields = $undo->fields();
        $note = static function (string $message) use (&$errors): void {
            if (count($errors) < self::MAX_ERRORS) {
                $errors[] = $message;
            }
        };

        foreach ($chunk as $id) {
            $version = $versions[(string) $id] ?? null;

            // In der Prüfphase gescheitert: dort schon gezählt und gemeldet.
            if ($version === false) {
                continue;
            }

            $record = $records->get($id);
            [$status, $values] = $undo->classify($record, $logged[(string) $id] ?? null);

            // Zwischen Prüfung und Schreiben zurückzunehmen geworden oder geblieben, aber ohne geprüfte Version.
            if ($status === BulkUndo::REVERT && $version === null) {
                $status = BulkUndo::CHANGED;
            }

            if ($status !== BulkUndo::REVERT || $record === null) {
                $counts[$status] = (int) ($counts[$status] ?? 0) + 1;

                if ($status === BulkUndo::CHANGED && $record !== null) {
                    $note(__('work.bulk.undo.changed_record', ['record' => self::title($undo->table, $record)]));
                }

                continue;
            }

            $old = array_intersect_key($record->getAttributes(), array_flip($fields));

            try {
                $this->writer->savepoint(fn () => $this->revert($undo, $record, $values, $options->expecting((string) $version)));
            } catch (StaleRecordException) {
                $counts[BulkUndo::CHANGED] = (int) ($counts[BulkUndo::CHANGED] ?? 0) + 1;
                $note(__('work.bulk.undo.changed_record', ['record' => self::title($undo->table, $record)]));

                continue;
            } catch (BusinessRuleException $e) {
                $failed++;
                $note(self::title($undo->table, $record).': '.$e->getMessage());

                continue;
            }

            $journal[] = $undo->head->operation === BulkChangeHead::UPDATE
                ? ['id' => $record->getKey(), 'old' => $old, 'new' => array_intersect_key($record->getAttributes(), array_flip($fields))]
                : ['id' => $record->getKey(), 'op' => $undo->head->inverseOperation()];
            $counts[BulkUndo::REVERT] = (int) ($counts[BulkUndo::REVERT] ?? 0) + 1;
        }

        self::writeJson($step, "journal-{$block}.json", $journal);
        $index = (int) $state['index'] + count($chunk);
        $state = [...$state, 'index' => $index, 'counts' => $counts, 'errors' => $errors, 'failed' => $failed];

        if ($index < count($ids)) {
            return StepOutcome::continue($state, count($chunk));
        }

        $reverted = (int) ($counts[BulkUndo::REVERT] ?? 0);
        $skipped = (int) ($counts[BulkUndo::CHANGED] ?? 0) + $failed + (int) ($counts[BulkUndo::NOT_LOGGED] ?? 0) + (int) ($counts[BulkUndo::GONE] ?? 0);
        $result = ['reverted' => $reverted, 'already' => (int) ($counts[BulkUndo::ALREADY] ?? 0), 'changed' => (int) ($counts[BulkUndo::CHANGED] ?? 0),
            'failed' => $failed, 'not_logged' => (int) ($counts[BulkUndo::NOT_LOGGED] ?? 0), 'gone' => (int) ($counts[BulkUndo::GONE] ?? 0), 'skipped' => $skipped];

        if ($reverted > 0) {
            $head = BulkChangeHead::recordRun((string) $state['run'], $undo->table, $undo->head->inverseOperation(), self::journalIds($step, $block), (array) $undo->head->field_changes, $result, $undo->head);
            $undo->head->forceFill(['undone_by_id' => $head->id])->save();
        }

        $message = trans_choice('work.bulk.undo.done', $reverted, ['count' => $reverted]);

        foreach (['already', 'changed', 'failed', 'not_logged', 'gone'] as $key) {
            if ($result[$key] > 0) {
                $message .= ' '.trans_choice("work.bulk.undo.{$key}", $result[$key], ['count' => $result[$key]]);
            }
        }

        if ($undo->droppedFields() !== [] && $undo->head->operation === BulkChangeHead::UPDATE) {
            $message .= ' '.__('work.bulk.undo.dropped', ['fields' => implode(', ', $undo->droppedFields())]);
        }

        return StepOutcome::finished(
            $message.($errors === [] ? '' : "\n".implode("\n", $errors).($failed + $result['changed'] > count($errors) ? "\n…" : '')),
            [...$result, 'run' => $state['run'], 'undo_of' => $undo->head->id],
            count($chunk),
        );
    }

    /** @param  array<string, mixed>  $values  alte Werte (Ändern) */
    private function revert(BulkUndo $undo, DynamicRecord $record, array $values, WriteOptions $options): void
    {
        match ($undo->head->operation) {
            BulkChangeHead::DELETE => $this->writer->restore($record, $options),
            BulkChangeHead::RESTORE => $this->writer->delete($record, $options),
            default => $this->writer->update($record, $values, $options),
        };
    }

    /**
     * Schlüssel aller Datensätze, die die Schreibphase geändert hat (aus den Journalen der Blöcke 0 bis $last).
     *
     * @return list<int|string>
     */
    private static function journalIds(WorkStep $step, int $last): array
    {
        $ids = [];

        for ($block = 0; $block <= $last; $block++) {
            foreach (self::readJson($step, "journal-{$block}.json") as $entry) {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }

    /**
     * Schlüssel der Datensätze, aufsteigend (Reihenfolge der Bearbeitung, auch für Nummern aus „Vergeben, wenn“).
     *
     * @param  array<string, mixed>  $parameters
     * @return list<int|string>
     */
    public static function ids(array $parameters): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int|string => is_numeric($id) ? (int) $id : (string) $id, (array) ($parameters['record_ids'] ?? []))));
        sort($ids);

        return $ids;
    }

    /**
     * Nicht gelöschte Datensätze des Blocks, nach Schlüssel.
     *
     * @param  list<int|string>  $chunk
     * @return Collection<int|string, DynamicRecord>
     */
    private function load(CustomTable $table, array $chunk, bool $trashed = false): Collection
    {
        return DynamicRecord::forTable($table)->newQuery()->when($trashed, static fn ($query) => $query->withTrashed())->whereKey($chunk)->get()->keyBy(static fn (DynamicRecord $record): int|string => $record->getKey());
    }

    /** Kurzbezeichnung für Meldungen: Schlüssel und erstes Textfeld der Liste. */
    private static function title(CustomTable $table, DynamicRecord $record): string
    {
        $field = $table->fields->first(static fn (CustomField $f): bool => $f->is_active && $f->show_in_table && in_array($f->type, [FieldType::String, FieldType::Number, FieldType::Select], true));
        $text = $field === null ? '' : trim((string) $record->getRawOriginal($field->name));

        return '#'.$record->getKey().($text === '' ? '' : ' „'.Str::limit($text, 40).'“');
    }

    private static function unchangedText(int $unchanged, int $skipped = 0): string
    {
        return ($unchanged > 0 ? ' '.trans_choice('work.bulk.unchanged', $unchanged, ['count' => $unchanged]) : '')
            .($skipped > 0 ? ' '.trans_choice('work.bulk.skipped', $skipped, ['count' => $skipped]) : '');
    }

    /** Stehen im Datensatz noch die Werte, die die Sammeländerung geschrieben hat? */
    private static function sameValues(CustomTable $table, DynamicRecord $current, array $written): bool
    {
        $expected = DynamicRecord::forTable($table)->newInstance();
        $expected->setRawAttributes($written);

        foreach (array_keys($written) as $field) {
            if (self::normalize($current->getAttribute($field)) !== self::normalize($expected->getAttribute($field))) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_numeric($value) => rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.'),
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /** @param  array<mixed>  $data */
    private static function writeJson(WorkStep $step, string $file, array $data): void
    {
        file_put_contents($step->path($file), (string) json_encode($data, JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<mixed> */
    private static function readJson(WorkStep $step, string $file): array
    {
        $path = $step->path($file);

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }
}
