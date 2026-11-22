<?php

declare(strict_types=1);

namespace App\Documents;

use App\Documents\Templates\RenderedDocument;
use App\Documents\Templates\TemplateException;
use App\DynamicData\Ui\RecordEntries;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use RuntimeException;
use ZipArchive;

/**
 * Dokumente für mehrere Datensätze auf einmal (B-124, D3a): je Datensatz wie „Dokument erstellen“ (Rechte, Ablage,
 * Prüfwert, Protokoll), zusammen als ein PDF zum Drucken oder als ZIP mit den einzelnen Dateien. Läuft direkt in der
 * Anfrage – deshalb höchstens max() Datensätze je Lauf.
 *
 * Was nicht abgelegt werden kann (Feld voll, Recht zum Bearbeiten fehlt), wird trotzdem erzeugt und gemeldet; scheitert
 * ein Dokument (z. B. Fehler in der Vorlage), fehlt es im Ergebnis und steht in der Liste der übersprungenen.
 */
final class DocumentBatch
{
    public const string PDF = 'pdf';

    public const string ZIP = 'zip';

    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly PdfMerger $merger,
        private readonly LetterheadResolver $letterheads,
        private readonly DocumentMailer $mailer,
    ) {}

    public static function max(): int
    {
        return max(1, (int) config('documents.batch_max', 50));
    }

    /**
     * @param  iterable<DynamicRecord>  $records
     * @param  bool  $send  je Datensatz per E-Mail an die Adresse aus der Vorlage (D3b); versendete werden abgelegt, wenn möglich
     * @return array{content: string, file_name: string, mime: string, generated: int, stored: int, sent: int, notes: list<string>}
     *
     * @throws BusinessRuleException wenn es zu viele Datensätze sind, keiner erzeugt werden konnte oder die Vorlage nicht erlaubt ist
     */
    public function run(DocumentTemplate $template, iterable $records, ?User $user, bool $store, string $format = self::PDF, bool $send = false, bool $copyToMe = false): array
    {
        $records = collect($records)->values();
        $table = $template->customTable ?? CustomTable::query()->findOrFail($template->custom_table_id);

        if ($records->count() > self::max()) {
            throw new BusinessRuleException(__('documents.batch.too_many', ['count' => $records->count(), 'max' => self::max()]), 'documents.batch_too_many');
        }

        $documents = [];
        $notes = [];
        $stored = 0;
        $sent = 0;
        $send = $send && DocumentMailer::canSend($user, $template);
        $store = $store || $send;

        foreach ($records as $record) {
            $title = RecordEntries::title($table, $record);
            $storeThis = $store && $this->generator->canStore($template, $table, $record, $user);

            if ($store && ! $storeThis && $this->generator->storageField($template, $table) !== null) {
                $notes[] = __('documents.batch.not_stored', ['title' => $title]);
            }

            try {
                $result = $this->generator->generate($template, $record, $user, $storeThis);
            } catch (BusinessRuleException $exception) {
                if ($exception->errorCode !== 'documents.field_full') {
                    throw $exception;
                }

                $notes[] = __('documents.batch.field_full', ['title' => $title, 'message' => $exception->getMessage()]);
                $result = $this->generator->generate($template, $record, $user, false);
            } catch (TemplateException $exception) {
                $notes[] = __('documents.batch.failed', ['title' => $title, 'message' => $exception->getMessage()]);

                continue;
            }

            $stored += $result['file'] !== null ? 1 : 0;
            $name = $result['file']?->name ?? $result['document']->fileName;
            $documents[] = ['name' => $name, 'pdf' => $result['document']->pdf];

            if ($send) {
                $note = $this->mail($template, $record, $user, $result, $name, $copyToMe);
                if ($note === null) {
                    $sent++;
                } else {
                    $notes[] = __('documents.batch.not_sent', ['title' => $title, 'message' => $note]);
                }
            }
        }

        if ($documents === []) {
            throw new BusinessRuleException(__('documents.batch.nothing', ['notes' => implode(' ', $notes)]), 'documents.batch_nothing');
        }

        // Dateiname: Name der Vorlage und Datum, ohne Zeichen, die Dateisysteme nicht erlauben.
        $base = trim(str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '-', $template->name)).'-'.now()->format('Y-m-d');

        $content = $format === self::ZIP
            ? $this->zip($documents)
            : $this->merger->merge(array_column($documents, 'pdf'), $this->letterheads->for($template->app_id)->pdfa, $template->name);

        return [
            'content' => $content,
            'file_name' => $base.($format === self::ZIP ? '.zip' : '.pdf'),
            'mime' => $format === self::ZIP ? 'application/zip' : 'application/pdf',
            'generated' => count($documents),
            'stored' => $stored,
            'sent' => $sent,
            'notes' => $notes,
        ];
    }

    /**
     * E-Mail für einen Datensatz; null bei Erfolg, sonst der Grund.
     *
     * @param  array{document: RenderedDocument, sha256: string, file: mixed}  $result
     */
    private function mail(DocumentTemplate $template, DynamicRecord $record, ?User $user, array $result, string $name, bool $copyToMe): ?string
    {
        try {
            $mail = $this->mailer->compose($template, $record, $user);

            if ($mail === null || $mail['to'] === []) {
                return __('documents.mail.no_address', ['value' => implode(', ', $mail['invalid'] ?? [])]);
            }

            $this->mailer->send($template, $record, $user, $result['document'], $name, $result['sha256'], $mail, $copyToMe);

            return null;
        } catch (BusinessRuleException|TemplateException $exception) {
            return $exception->getMessage();
        } catch (\Throwable $exception) {
            report($exception);

            return DocumentMailer::failure($exception);
        }
    }

    /** @param  list<array{name: string, pdf: string}>  $documents */
    private function zip(array $documents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'saft-documents-');

        if ($path === false) {
            throw new RuntimeException('Temporäre Datei für das ZIP nicht möglich.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP nicht möglich.');
            }

            $names = [];

            foreach ($documents as $document) {
                $name = DocumentGenerator::versionedName($document['name'], $names);
                $names[] = $name;
                $zip->addFromString($name, $document['pdf']);
            }

            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
