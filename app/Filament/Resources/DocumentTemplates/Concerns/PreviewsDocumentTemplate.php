<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Concerns;

use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateException;
use App\Documents\Templates\TemplateFields;
use App\Documents\Templates\TemplateRenderer;
use App\Filament\Resources\DocumentTemplates\Schemas\DocumentTemplateForm;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Live-Vorschau einer Vorlage auf der Seite: nach jeder Änderung (verzögert) das PDF aus dem ungespeicherten Stand mit
 * dem gewählten Beispieldatensatz (sonst dem neuesten). Das PDF geht als Ereignis an den Browser, nicht als Eigenschaft –
 * es soll nicht bei jeder Anfrage hin- und hergeschickt werden.
 */
trait PreviewsDocumentTemplate
{
    public function updatedData(): void
    {
        $this->refreshPreview();
    }

    public function refreshPreview(): void
    {
        $data = (array) $this->data;
        $record = $this instanceof EditRecord ? $this->getRecord() : null;
        $template = DocumentTemplateForm::transient($data, $record instanceof DocumentTemplate ? $record : null);

        if ($template === null || blank($template->body)) {
            $this->dispatch('document-template-preview', pdf: null, message: __('documents.templates.preview.choose_table'));

            return;
        }

        $table = CustomTable::query()->find($template->custom_table_id);
        $model = DynamicRecord::forTableName((string) $table?->name);
        $sample = filled($data['sample_id'] ?? null)
            ? $model->newQuery()->find($data['sample_id'])
            : ($table === null ? null : self::defaultSample($table, (string) $template->recipient));

        if ($table === null || $sample === null) {
            $this->dispatch('document-template-preview', pdf: null, message: __('documents.templates.preview.no_records'));

            return;
        }

        $template->setRelation('customTable', $table);
        $errors = app(TemplateChecker::class)->check($table, (string) $template->body, $template->recipient, $template->file_name, $template->only(['email_to', 'email_subject', 'email_body', 'email_reply_to']))['errors'];

        if ($errors !== []) {
            $this->dispatch('document-template-preview', pdf: null, message: implode("\n", $errors));

            return;
        }

        try {
            $user = auth()->user();
            $document = app(TemplateRenderer::class)->render($template, $sample, $user instanceof User ? $user : null);
            $message = $document->fileName.(filled(trim((string) $template->recipient)) && $document->recipient === null ? ' – '.__('documents.templates.preview.no_recipient') : '');
            $this->dispatch('document-template-preview', pdf: base64_encode($document->pdf), message: $message, mail: $this->mailPreview($template, $sample));
        } catch (TemplateException $exception) {
            $this->dispatch('document-template-preview', pdf: null, message: $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('document-template-preview', pdf: null, message: __('documents.templates.preview.failed', ['message' => $exception->getMessage()]));
        }
    }

    /** E-Mail zur Vorlage für den Beispieldatensatz als Text (D3b); null ohne „An“. */
    private function mailPreview(DocumentTemplate $template, DynamicRecord $sample): ?string
    {
        $user = auth()->user();
        $mail = app(TemplateRenderer::class)->mail($template, $sample, $user instanceof User ? $user : null);

        if ($mail === null) {
            return null;
        }

        $to = implode(', ', $mail['to']).($mail['invalid'] !== [] ? ' – '.__('documents.mail.no_address', ['value' => implode(', ', $mail['invalid'])]) : '');

        return implode("\n", array_filter([
            __('documents.mail.to').': '.($to !== '' ? $to : '–'),
            $mail['reply_to'] !== null ? __('documents.templates.fields.email_reply_to').': '.$mail['reply_to'] : null,
            __('documents.mail.subject').': '.$mail['subject'],
            '',
            $mail['body'],
        ], static fn (?string $line): bool => $line !== null));
    }

    /**
     * Beispiel ohne Auswahl: der neueste Datensatz, bei dem ein Feld der Grundtabelle aus dem Empfänger gefüllt ist (im
     * Gitarrenshop ein Beleg mit Kunde, nicht ein Einkauf) – sonst der neueste.
     */
    public static function defaultSample(CustomTable $table, string $recipient): ?DynamicRecord
    {
        $model = DynamicRecord::forTableName($table->name);
        preg_match_all('/\{\{\s*[#^&{]?\s*([a-z][a-z0-9_]*)/', $recipient, $matches);
        $fields = array_values(array_intersect(array_unique($matches[1]), TemplateFields::fields($table)->pluck('name')->all()));
        $newest = static fn (Builder $query): ?DynamicRecord => $query->orderByDesc($model->getKeyName())->first();

        if ($fields !== []) {
            $filled = $newest($model->newQuery()->where(static function ($query) use ($fields): void {
                foreach ($fields as $field) {
                    $query->orWhereNotNull($field);
                }
            }));

            if ($filled !== null) {
                return $filled;
            }
        }

        return $newest($model->newQuery());
    }

    /** Hinweise (CSS, Bilder von aussen) nach dem Speichern. */
    protected function notifyTemplateWarnings(DocumentTemplate $template): void
    {
        $table = $template->customTable;
        $warnings = $table === null ? [] : app(TemplateChecker::class)->check($table, (string) $template->body)['warnings'];

        if ($warnings !== []) {
            Notification::make()->title(__('documents.templates.check.warnings_title'))->body(implode("\n\n", $warnings))->warning()->persistent()->send();
        }
    }
}
