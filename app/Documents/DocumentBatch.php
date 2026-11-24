<?php

declare(strict_types=1);

namespace App\Documents;

use App\Documents\Templates\RenderedDocument;
use App\Documents\Templates\TemplateException;
use App\DynamicData\Ui\RecordEntries;
use App\DynamicData\Write\WriteContext;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use Closure;
use RuntimeException;
use ZipArchive;

/**
 * Dokumente für mehrere Datensätze auf einmal (B-124, D3a): je Datensatz wie „Dokument erstellen“ (Rechte, Ablage,
 * Prüfwert, Protokoll), zusammen als ein PDF zum Drucken oder als ZIP mit den einzelnen Dateien. Die Schleife über die
 * Datensätze liegt in der Arbeit „Dokumente erstellen“ (App\Work\Types\DocumentsWork, Fundament 2, F3b); hier stehen
 * die Bausteine: one() für ein Dokument, assemble() für die Ergebnisdatei.
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

    /** Obergrenze je Sammellauf (documents.batch_max); 0 = ohne Grenze. */
    public static function max(): int
    {
        return max(0, (int) config('documents.batch_max', 0));
    }

    /** Dateiname des Ergebnisses: Name der Vorlage und Datum, ohne Zeichen, die Dateisysteme nicht erlauben. */
    public static function fileName(DocumentTemplate $template, string $format): string
    {
        $base = trim(str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '-', $template->name)).'-'.now()->format('Y-m-d');

        return $base.($format === self::ZIP ? '.zip' : '.pdf');
    }

    /**
     * Ein Dokument für einen Datensatz. Versendet wird erst nach dem Commit des laufenden Schreibvorgangs
     * (WriteContext::afterCommit): Ein zurückgerollter Block hinterlässt keine E-Mail. $mailed erfährt je Versand null
     * (gesendet) oder den Hinweis, warum nicht.
     *
     * @param  bool  $send  je Datensatz per E-Mail an die Adresse aus der Vorlage (D3b); versendete werden abgelegt, wenn möglich
     * @param  string  $document  Format des Dokuments (D4b): pdf oder docx – Word wird nie abgelegt oder versendet
     * @param  list<string>  $notes  Hinweise (nicht abgelegt, kein Dokument) kommen hier dazu
     * @param  (Closure(?string): void)|null  $mailed
     * @return array{name: string, content: string, stored: bool}|null null, wenn kein Dokument entstanden ist
     *
     * @throws BusinessRuleException wenn die Vorlage für die Person nicht erlaubt ist
     */
    public function one(DocumentTemplate $template, CustomTable $table, DynamicRecord $record, ?User $user, bool $store, bool $send, bool $copyToMe, string $document, array &$notes, ?Closure $mailed = null): ?array
    {
        if ($document === DocumentTemplate::FORMAT_DOCX) {
            [$store, $send] = [false, false];
        }

        $title = RecordEntries::title($table, $record);
        $send = $send && DocumentMailer::canSend($user, $template);
        $store = $store || $send;
        $storeThis = $store && $this->generator->canStore($template, $table, $record, $user);

        if ($store && ! $storeThis && $this->generator->storageField($template, $table) !== null) {
            $notes[] = (string) __('documents.batch.not_stored', ['title' => $title]);
        }

        try {
            $result = $this->generator->generate($template, $record, $user, $storeThis, $document);
        } catch (BusinessRuleException $exception) {
            if ($exception->errorCode !== 'documents.field_full') {
                throw $exception;
            }

            $notes[] = (string) __('documents.batch.field_full', ['title' => $title, 'message' => $exception->getMessage()]);
            $result = $this->generator->generate($template, $record, $user, false);
        } catch (TemplateException $exception) {
            $notes[] = (string) __('documents.batch.failed', ['title' => $title, 'message' => $exception->getMessage()]);

            return null;
        }

        $name = $result['file']?->name ?? $result['document']->fileName;

        if ($send) {
            WriteContext::afterCommit(function () use ($template, $record, $user, $result, $name, $copyToMe, $title, $mailed): void {
                $note = $this->mail($template, $record, $user, $result, $name, $copyToMe);
                $mailed?->__invoke($note === null ? null : (string) __('documents.batch.not_sent', ['title' => $title, 'message' => $note]));
            });
        }

        return ['name' => $name, 'content' => $result['document']->content(), 'stored' => $result['file'] !== null];
    }

    /**
     * Die Ergebnisdatei bauen: ein PDF mit allen Dokumenten oder ein ZIP mit einer Datei je Dokument.
     *
     * @param  list<array{name: string, path: string}>  $documents  Namen sind eindeutig (DocumentGenerator::versionedName())
     *
     * @throws BusinessRuleException wenn es keine Dokumente gibt
     */
    public function assemble(DocumentTemplate $template, string $format, array $documents, string $target, string $notes = ''): void
    {
        if ($documents === []) {
            throw new BusinessRuleException(__('documents.batch.nothing', ['notes' => $notes]), 'documents.batch_nothing');
        }

        if ($format === self::ZIP) {
            $this->zip($documents, $target);

            return;
        }

        $contents = array_map(static fn (array $document): string => (string) file_get_contents($document['path']), $documents);

        if (file_put_contents($target, $this->merger->merge($contents, $this->letterheads->for($template->app_id)->pdfa, $template->name)) === false) {
            throw new RuntimeException('Ergebnisdatei nicht möglich.');
        }
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

    /** @param  list<array{name: string, path: string}>  $documents */
    private function zip(array $documents, string $target): void
    {
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP nicht möglich.');
        }

        foreach ($documents as $document) {
            $zip->addFile($document['path'], $document['name']);
        }

        if (! $zip->close()) {
            throw new RuntimeException('ZIP nicht möglich.');
        }
    }
}
