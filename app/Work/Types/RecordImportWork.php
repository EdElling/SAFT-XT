<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\DynamicData\Import\RecordImporter;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Work\CleansUp;
use App\Work\StepOutcome;
use App\Work\TimeLimit;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;
use Illuminate\Support\Facades\Auth;

/**
 * Arbeit „Daten importieren“ (Fundament 2, F3b): neue Datensätze aus einer Excel- oder CSV-Datei in eine Tabelle des
 * Table-Designers, alles oder nichts – ohne lange Transaktion. Die Datei liegt im Ordner der Arbeit (WorkService::start
 * mit $files). Zwei Phasen, Cursor ist jeweils die Zeilennummer:
 *
 * - „prüfen“: Zeilen blockweise wandeln und prüfen wie beim Erfassen (RecordImporter::convert()); Werte eindeutiger Felder
 *   wandern über unique.json von Schritt zu Schritt, Meldungen (bis MAX_MESSAGES) und Zähler im Zustand. Fehler am Ende
 *   der Datei → Arbeit fehlgeschlagen mit der Liste, nichts angelegt.
 * - „einspielen“: Datei erneut lesen, bis zum Cursor überspringen, Datensätze über den Writer anlegen (Vorgaben,
 *   berechnete Felder, Summen in übergeordneten Datensätzen greifen; Plugins prüfen mit). Jeder Schritt ist eine
 *   Transaktion samt Fortschritt; seine IDs stehen vor dem Commit in created.ids. Schlägt ein späterer Schritt fehl oder
 *   wird abgebrochen, löscht abort() die bereits angelegten Datensätze endgültig. Am Ende ein Protokolleintrag „importiert“.
 *
 * Parameter: table_id, file (Name der hochgeladenen Datei), source (Name der Kopie im Ordner), rows (Datenzeilen laut
 * Assistent, für den Fortschritt).
 */
final readonly class RecordImportWork implements CleansUp, WorkType, WritesRecords
{
    public const string KEY = 'record_import';

    public const string CHECK = 'check';

    public const string IMPORT = 'import';

    /** Name der Kopie der hochgeladenen Datei im Ordner der Arbeit (mit der Endung des Originals). */
    public const string SOURCE = 'source';

    /** So viele Zeilen prüft ein Schritt mindestens, bevor er aufs Budget schaut. */
    public const int CHUNK = 200;

    /** Datensätze je Transaktion beim Zurücknehmen. */
    private const int ABORT_CHUNK = 500;

    /** Zeit, die das Zurücknehmen bekommt (das Zeitlimit des Treibers ist dafür nicht bemessen). */
    private const int ABORT_SECONDS = 600;

    private const string UNIQUE = 'unique.json';

    private const string CREATED = 'created.ids';

    public function __construct(private RecordImporter $importer, private RecordWriter $writer, private TimeLimit $timeLimit) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.record_import');
    }

    public function describe(array $parameters): string
    {
        $table = CustomTable::query()->find((int) ($parameters['table_id'] ?? 0));

        return trim(($table === null ? '' : (string) $table->label).' · '.(string) ($parameters['file'] ?? ''), ' ·');
    }

    public function prepare(array $parameters): WorkPlan
    {
        $rows = isset($parameters['rows']) ? (int) $parameters['rows'] : null;

        return new WorkPlan(
            ['phase' => self::CHECK, 'line' => 0, 'rows' => 0, 'errors' => 0, 'messages' => [], 'created' => 0],
            $rows === null ? null : $rows * 2,
        );
    }

    public function step(WorkStep $step): StepOutcome
    {
        $table = CustomTable::query()->with('fields')->find((int) ($step->parameters['table_id'] ?? 0))
            ?? throw new BusinessRuleException(__('work.import.table_missing'));
        $source = $step->path((string) ($step->parameters['source'] ?? self::SOURCE));
        $fileName = (string) ($step->parameters['file'] ?? '');

        if (! is_file($source)) {
            throw new BusinessRuleException(__('work.import.file_missing'));
        }

        [$columns, , $missing] = $this->importer->columns($table, $source, $fileName);

        return ($step->state['phase'] ?? self::CHECK) === self::IMPORT
            ? $this->import($step, $table, $columns, $source, $fileName)
            : $this->check($step, $table, $columns, $missing, $source, $fileName);
    }

    /**
     * Zurücknehmen: alle Datensätze, die Schritte dieser Arbeit angelegt haben, endgültig löschen – ohne Ereignisse an
     * Plugins (eine Geschäftsregel darf das Zurücknehmen nicht verhindern) und ohne Protokoll je Datensatz.
     */
    public function abort(WorkStep $step, string $status, string $message): void
    {
        $file = $step->path(self::CREATED);
        $table = CustomTable::query()->find((int) ($step->parameters['table_id'] ?? 0));

        if (! is_file($file) || $table === null) {
            return;
        }

        $ids = array_values(array_unique(array_filter((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return;
        }

        $this->timeLimit->extend(self::ABORT_SECONDS);
        $options = WriteOptions::import()->withoutPluginEvents();

        foreach (array_chunk($ids, self::ABORT_CHUNK) as $chunk) {
            $this->writer->batch(function () use ($table, $chunk, $options): void {
                foreach (DynamicRecord::forTable($table)->newQuery()->withTrashed()->whereKey($chunk)->get() as $record) {
                    $this->writer->forceDelete($record, $options);
                }
            }, $options);
        }
    }

    /**
     * Phase „prüfen“: Zeilen ab dem Cursor wandeln und prüfen, mindestens CHUNK, dann bis das Budget knapp wird.
     *
     * @param  array<int, CustomField>  $columns
     * @param  list<string>  $missing
     */
    private function check(WorkStep $step, CustomTable $table, array $columns, array $missing, string $source, string $fileName): StepOutcome
    {
        $state = $step->state;
        $line = (int) ($state['line'] ?? 0);
        $unique = $this->uniqueValues($step, $line);

        if ($unique === null) {
            // Die Werte eindeutiger Felder passen nicht zum gespeicherten Stand (Schritt zurückgerollt): von vorn.
            [$line, $unique, $state] = [0, [], [...$state, 'rows' => 0, 'errors' => 0, 'messages' => []]];
        }

        $rows = (int) ($state['rows'] ?? 0);
        $errors = (int) ($state['errors'] ?? 0);
        $messages = array_values((array) ($state['messages'] ?? []));
        $maxRows = max(0, (int) config('dynamic_data.import.max_record_rows'));
        $lookups = [];
        $count = 0;
        $complete = true;

        foreach ($this->importer->read($source, $fileName, $line) as $number => $cells) {
            if ($maxRows > 0 && $rows >= $maxRows) {
                throw new BusinessRuleException(__('import.records.too_many_rows', ['max' => $maxRows]));
            }

            $rows++;
            $count++;
            $line = $number;

            foreach ($this->importer->convert($table, $columns, $number, $cells, $lookups, $unique)['problems'] as $message) {
                $errors++;

                if (count($messages) < RecordImporter::MAX_MESSAGES) {
                    $messages[] = $message;
                }
            }

            if ($count >= self::CHUNK && $step->remaining() < 1) {
                $complete = false;

                break;
            }
        }

        if (! $complete) {
            file_put_contents($step->path(self::UNIQUE), json_encode(['line' => $line, 'values' => $unique], JSON_THROW_ON_ERROR), LOCK_EX);

            return StepOutcome::continue([...$state, 'line' => $line, 'rows' => $rows, 'errors' => $errors, 'messages' => $messages], $count);
        }

        $problems = [
            ...($missing === [] ? [] : [(string) __('data.import.missing_required', ['columns' => implode(', ', $missing)])]),
            ...($rows === 0 ? [(string) __('data.import.no_rows')] : []),
            ...$messages,
            ...($errors > count($messages) ? [(string) trans_choice('data.import.more', $errors - count($messages), ['count' => $errors - count($messages)])] : []),
        ];

        if ($problems !== []) {
            throw new BusinessRuleException(__('import.records.invalid')."\n".implode("\n", $problems), 'import.invalid');
        }

        @unlink($step->path(self::UNIQUE));

        return StepOutcome::continue([...$state, 'phase' => self::IMPORT, 'line' => 0, 'rows' => $rows, 'errors' => 0, 'messages' => []], $count);
    }

    /**
     * Phase „einspielen“: Datensätze ab dem Cursor anlegen, mindestens einen, dann bis das Budget knapp wird.
     *
     * @param  array<int, CustomField>  $columns
     */
    private function import(WorkStep $step, CustomTable $table, array $columns, string $source, string $fileName): StepOutcome
    {
        $state = $step->state;
        $line = (int) ($state['line'] ?? 0);
        $created = (int) ($state['created'] ?? 0);
        $lookups = [];
        $unique = [];
        $ids = [];
        $complete = true;

        try {
            foreach ($this->importer->read($source, $fileName, $line) as $number => $cells) {
                $converted = $this->importer->convert($table, $columns, $number, $cells, $lookups, $unique);

                if ($converted['problems'] !== []) {
                    throw new BusinessRuleException(__('import.records.invalid')."\n".implode("\n", $converted['problems']), 'import.invalid');
                }

                try {
                    $ids[] = (string) $this->writer->create($table, $converted['values'], WriteOptions::import())->getKey();
                } catch (RecordRejected $e) {
                    // Abgelehnt von einem Plugin (Ereignis beim Speichern): als Fehler der Zeile melden.
                    throw new BusinessRuleException(__('import.records.row_error', ['row' => $number, 'message' => $e->getMessage()]), previous: $e);
                }

                $line = $number;

                if ($step->remaining() < 1) {
                    $complete = false;

                    break;
                }
            }
        } finally {
            // Vor dem Commit festhalten, was abort() zurücknehmen müsste; zu viel ist unschädlich, zu wenig nicht.
            if ($ids !== []) {
                file_put_contents($step->path(self::CREATED), implode("\n", $ids)."\n", FILE_APPEND | LOCK_EX);
            }
        }

        $created += count($ids);

        if (! $complete) {
            return StepOutcome::continue([...$state, 'line' => $line, 'created' => $created], count($ids));
        }

        activity('data')
            ->performedOn($table)
            ->causedBy(Auth::user())
            ->event('imported')
            ->withChanges(['attributes' => [
                'datei' => $fileName,
                'datensaetze' => $created,
                'spalten' => count($columns),
            ]])
            ->log('imported');

        foreach ([$source, $step->path(self::CREATED), $step->path(self::UNIQUE)] as $file) {
            @unlink($file);
        }

        return StepOutcome::finished(trans_choice('data.import.done', $created, ['count' => $created]), ['records' => $created], count($ids));
    }

    /**
     * Werte eindeutiger Felder aus den bisherigen Prüfschritten; null, wenn sie nicht zum Cursor passen.
     *
     * @return array<string, array<string, true>>|null
     */
    private function uniqueValues(WorkStep $step, int $line): ?array
    {
        if ($line === 0) {
            return [];
        }

        $file = $step->path(self::UNIQUE);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($saved) && (int) ($saved['line'] ?? -1) === $line ? (array) ($saved['values'] ?? []) : null;
    }
}
