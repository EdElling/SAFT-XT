<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;
use Illuminate\Support\Facades\Auth;

/**
 * Arbeit „Dokumente erstellen“ (Fundament 2, F3b): je Datensatz ein Dokument aus einer Vorlage, abgelegt und versendet
 * wie bei der Einzelaktion, am Ende ein PDF mit allen Dokumenten oder ein ZIP. Jeder Schritt erzeugt Dokumente, bis sein
 * Zeitbudget knapp wird (mindestens eines), und legt sie als Dateien in den Ordner der Arbeit; der letzte Schritt fügt
 * sie zusammen. Schreibender Schritt: Ablage und Fortschritt sind eine Transaktion, E-Mails gehen nach dem Commit
 * (DocumentBatch::one()) und melden ihr Ergebnis in eine Datei des Ordners, die der letzte Schritt auswertet.
 *
 * Parameter: template_id, record_ids, store, format (pdf|zip), send, copy, document (pdf|docx), file (Dateiname).
 */
final readonly class DocumentsWork implements WorkType, WritesRecords
{
    public const string KEY = 'documents';

    /** Höchstens so viele Hinweise bleiben einzeln erhalten, die übrigen werden gezählt. */
    public const int MAX_NOTES = 50;

    /** Je Versand nach dem Commit eine Zeile: „ok“ oder der Hinweis. */
    private const string MAILS = 'mails.log';

    public function __construct(private DocumentBatch $batch) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.documents');
    }

    public function describe(array $parameters): string
    {
        $template = DocumentTemplate::query()->find((int) ($parameters['template_id'] ?? 0));
        $count = count((array) ($parameters['record_ids'] ?? []));

        return trim(($template === null ? '' : $template->name).' · '.trans_choice('documents.batch.heading', $count, ['count' => $count]), ' ·');
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['index' => 0, 'generated' => 0, 'stored' => 0, 'notes' => [], 'more' => 0, 'documents' => []], count((array) ($parameters['record_ids'] ?? [])));
    }

    public function step(WorkStep $step): StepOutcome
    {
        $template = DocumentTemplate::query()->with('customTable')->find((int) ($step->parameters['template_id'] ?? 0))
            ?? throw new BusinessRuleException(__('documents.generate.not_allowed'), 'documents.not_allowed');
        $table = $template->customTable ?? CustomTable::query()->findOrFail($template->custom_table_id);
        $ids = array_values((array) ($step->parameters['record_ids'] ?? []));
        $state = $step->state;
        $index = (int) ($state['index'] ?? 0);

        if ($index >= count($ids)) {
            return $this->assemble($step, $template, $state);
        }

        $user = Auth::user() instanceof User ? Auth::user() : null;
        $documents = array_values((array) ($state['documents'] ?? []));
        $notes = [];
        $mails = $step->path(self::MAILS);
        $mailed = static function (?string $note) use ($mails): void {
            file_put_contents($mails, ($note ?? 'ok')."\n", FILE_APPEND | LOCK_EX);
        };
        $first = $index;

        // Mindestens ein Dokument je Schritt, weitere, solange das Budget reicht.
        while ($index < count($ids) && ($index === $first || $step->remaining() > 3)) {
            $record = DynamicRecord::forTable($table)->newQuery()->find($ids[$index]);

            if ($record === null) {
                $notes[] = (string) __('documents.batch.failed', ['title' => '#'.$ids[$index], 'message' => __('errors.rules.record_deleted')]);
            } elseif (($result = $this->batch->one(
                $template,
                $table,
                $record,
                $user,
                (bool) ($step->parameters['store'] ?? false),
                (bool) ($step->parameters['send'] ?? false),
                (bool) ($step->parameters['copy'] ?? false),
                (string) ($step->parameters['document'] ?? DocumentTemplate::FORMAT_PDF),
                $notes,
                $mailed,
            )) !== null) {
                $name = DocumentGenerator::versionedName($result['name'], $documents);
                file_put_contents($step->path(self::partName(count($documents))), $result['content']);
                $documents[] = $name;
                $state['generated'] = (int) ($state['generated'] ?? 0) + 1;
                $state['stored'] = (int) ($state['stored'] ?? 0) + ($result['stored'] ? 1 : 0);
            }

            $index++;
        }

        return StepOutcome::continue([...$state, ...self::withNotes($state, $notes), 'index' => $index, 'documents' => $documents], $index - $first);
    }

    /** @param  array<string, mixed>  $state */
    private function assemble(WorkStep $step, DocumentTemplate $template, array $state): StepOutcome
    {
        $names = array_values((array) ($state['documents'] ?? []));
        $documents = array_map(fn (int $i): array => ['name' => $names[$i], 'path' => $step->path(self::partName($i))], array_keys($names));
        $format = (string) ($step->parameters['format'] ?? DocumentBatch::PDF);
        $file = basename((string) ($step->parameters['file'] ?? DocumentBatch::fileName($template, $format)));
        $sent = 0;
        $notes = [];

        // Ergebnisse der E-Mails, die nach den Commits der Schritte gingen.
        $log = $step->path(self::MAILS);

        foreach (is_file($log) ? (array) file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            if ($line === 'ok') {
                $sent++;
            } else {
                $notes[] = $line;
            }
        }

        $state = [...$state, ...self::withNotes($state, $notes)];
        $allNotes = self::notes($state);
        $this->batch->assemble($template, $format, $documents, $step->path($file), implode(' ', $allNotes));

        foreach (array_keys($names) as $i) {
            @unlink($step->path(self::partName($i)));
        }
        @unlink($log);

        $generated = (int) ($state['generated'] ?? 0);
        $message = trans_choice('documents.batch.done', $generated, ['count' => $generated, 'stored' => (int) ($state['stored'] ?? 0)])
            .(! empty($step->parameters['send']) ? ', '.trans_choice('documents.batch.sent', $sent, ['count' => $sent]) : '');

        return StepOutcome::finished(
            $allNotes === [] ? $message : $message."\n".implode("\n", $allNotes),
            ['file' => $file, 'generated' => $generated, 'stored' => (int) ($state['stored'] ?? 0), 'sent' => $sent, 'notes' => $allNotes],
        );
    }

    private static function partName(int $index): string
    {
        return sprintf('%05d.bin', $index);
    }

    /**
     * Hinweise anhängen, höchstens MAX_NOTES einzeln, der Rest gezählt.
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $notes
     * @return array{notes: list<string>, more: int}
     */
    private static function withNotes(array $state, array $notes): array
    {
        $kept = array_values((array) ($state['notes'] ?? []));
        $more = (int) ($state['more'] ?? 0);

        foreach ($notes as $note) {
            if (count($kept) < self::MAX_NOTES) {
                $kept[] = $note;
            } else {
                $more++;
            }
        }

        return ['notes' => $kept, 'more' => $more];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private static function notes(array $state): array
    {
        $notes = array_values((array) ($state['notes'] ?? []));
        $more = (int) ($state['more'] ?? 0);

        return $more > 0 ? [...$notes, trans_choice('documents.batch.more', $more, ['count' => $more])] : $notes;
    }
}
