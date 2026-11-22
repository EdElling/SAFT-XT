<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\Documents\LetterheadResolver;
use App\Documents\PdfRenderer;
use App\Documents\QrBill\QrBillFactory;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Support\AppLocale;
use Illuminate\Support\Carbon;

/**
 * Setzt eine Inhaltsvorlage für einen Datensatz um und erzeugt das PDF auf dem Briefpapier der App. Die Sprache des
 * Dokuments kommt aus dem Sprachfeld der Vorlage (de, en, fr, it), sonst aus der Sprache der Person; Bezeichnungen und
 * Formate folgen ihr. Auch für ungespeicherte Vorlagen (Live-Vorschau).
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly TemplateEngine $engine,
        private readonly LetterheadResolver $letterheads,
        private readonly PdfRenderer $pdf,
        private readonly QrBillFactory $qrBills,
    ) {}

    /** @throws TemplateException */
    public function render(DocumentTemplate $template, DynamicRecord $record, ?User $user): RenderedDocument
    {
        $table = $template->customTable ?? CustomTable::query()->findOrFail($template->custom_table_id);
        $locale = $this->locale($template, $record);
        $previous = [app()->getLocale(), Carbon::getLocale()];
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        try {
            $letterhead = $this->letterheads->for($template->app_id);
            $context = new DocumentContext(new RecordContext($record, $table, $user, $locale), $letterhead, $locale);
            $html = $this->engine->html($template->body, $context);
            $recipient = $this->recipient((string) $template->recipient, $context);
            $fileName = self::fileName($this->engine->text((string) $template->file_name, $context), $template->name);
            // QR-Rechnung (D4a): ungültige Zahlungsdaten ergeben eine TemplateException mit Grund.
            $qr = QrBillFactory::enabled($template->qr_bill) ? $this->qrBills->build((array) $template->qr_bill, $context, $letterhead) : null;
            $pdf = $this->pdf->render($html, $letterhead, [
                'title' => pathinfo($fileName, PATHINFO_FILENAME), 'lang' => $locale, 'address' => $recipient,
                'qr' => $qr, 'qr_position' => (string) ($template->qr_bill['position'] ?? QrBillFactory::PAGE),
            ]);
        } finally {
            app()->setLocale($previous[0]);
            Carbon::setLocale($previous[1]);
        }

        return new RenderedDocument($html, $recipient, $fileName, $locale, $pdf);
    }

    /**
     * E-Mail zur Vorlage für einen Datensatz (B-124, D3b): An (gültige und ungültige Adressen getrennt), Betreff, Text und
     * Antwortadresse – in der Sprache des Dokuments. null, wenn die Vorlage keine Adresse vorsieht.
     *
     * @return array{to: list<string>, invalid: list<string>, subject: string, body: string, reply_to: ?string}|null
     *
     * @throws TemplateException
     */
    public function mail(DocumentTemplate $template, DynamicRecord $record, ?User $user): ?array
    {
        if (blank($template->email_to)) {
            return null;
        }

        $table = $template->customTable ?? CustomTable::query()->findOrFail($template->custom_table_id);
        $locale = $this->locale($template, $record);
        $previous = [app()->getLocale(), Carbon::getLocale()];
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        try {
            $context = new DocumentContext(new RecordContext($record, $table, $user, $locale), $this->letterheads->for($template->app_id), $locale);
            $text = fn (?string $part): string => trim($this->engine->text((string) $part, $context));
            [$to, $invalid] = self::addresses($text($template->email_to));
            $replyTo = self::addresses($text($template->email_reply_to))[0][0] ?? null;

            return ['to' => $to, 'invalid' => $invalid, 'subject' => $text($template->email_subject), 'body' => $text($template->email_body), 'reply_to' => $replyTo];
        } finally {
            app()->setLocale($previous[0]);
            Carbon::setLocale($previous[1]);
        }
    }

    /**
     * Adressen aus einem Text (getrennt mit Komma, Strichpunkt oder Zeilenumbruch): gültige und ungültige.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function addresses(string $text): array
    {
        $valid = [];
        $invalid = [];

        foreach (preg_split('/[,;\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $address;
            } else {
                $invalid[] = $address;
            }
        }

        return [array_values(array_unique($valid)), $invalid];
    }

    /** Sprache aus dem Sprachfeld der Vorlage, wenn es eine angebotene Sprache enthält; sonst die aktuelle. */
    public function locale(DocumentTemplate $template, DynamicRecord $record): string
    {
        $value = filled($template->language_field) ? strtolower(trim((string) $record->getAttribute((string) $template->language_field))) : null;

        return AppLocale::isAvailable($value) ? (string) $value : app()->getLocale();
    }

    /** Empfänger: eine Zeile je Adresszeile, leere Zeilen fallen weg; null ohne Zeile. */
    private function recipient(string $template, DocumentContext $context): ?string
    {
        if (trim($template) === '') {
            return null;
        }

        $lines = array_filter(array_map('trim', preg_split('/\R/u', $this->engine->html($template, $context)) ?: []), static fn (string $line): bool => $line !== '');

        return $lines === [] ? null : implode('<br>', $lines);
    }

    /** Dateiname ohne Zeichen, die Dateisysteme nicht erlauben; leer: Name der Vorlage. Immer mit .pdf. */
    public static function fileName(string $name, string $fallback): string
    {
        $clean = static fn (string $value): string => trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', preg_replace('/\s+/u', ' ', $value) ?? ''), ' .-');
        $name = $clean($name);

        return ($name !== '' ? $name : ($clean($fallback) ?: 'Dokument')).'.pdf';
    }
}
