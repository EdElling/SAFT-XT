<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Concerns;

use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateException;
use App\Documents\Templates\TemplateRenderer;
use App\Filament\Resources\DocumentTemplates\Schemas\DocumentTemplateForm;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
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
            : $model->newQuery()->orderByDesc($model->getKeyName())->first();

        if ($table === null || $sample === null) {
            $this->dispatch('document-template-preview', pdf: null, message: __('documents.templates.preview.no_records'));

            return;
        }

        $template->setRelation('customTable', $table);
        $errors = app(TemplateChecker::class)->check($table, (string) $template->body, $template->recipient, $template->file_name)['errors'];

        if ($errors !== []) {
            $this->dispatch('document-template-preview', pdf: null, message: implode("\n", $errors));

            return;
        }

        try {
            $user = auth()->user();
            $document = app(TemplateRenderer::class)->render($template, $sample, $user instanceof User ? $user : null);
            $this->dispatch('document-template-preview', pdf: base64_encode($document->pdf), message: $document->fileName);
        } catch (TemplateException $exception) {
            $this->dispatch('document-template-preview', pdf: null, message: $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('document-template-preview', pdf: null, message: __('documents.templates.preview.failed', ['message' => $exception->getMessage()]));
        }
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
