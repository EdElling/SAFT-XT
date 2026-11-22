<?php

declare(strict_types=1);

namespace App\Documents;

use App\Apps\AppPermissions;
use App\Documents\Templates\RenderedDocument;
use App\Documents\Templates\TemplateFields;
use App\Documents\Templates\TemplateRenderer;
use App\DynamicData\DataAccess;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Dokument aus einer Vorlage für einen Datensatz erzeugen (B-124, D1c): PDF zum Herunterladen, auf Wunsch abgelegt im
 * Dateifeld der Vorlage – immer als neue Datei (gleicher Name → „Name (2).pdf“), schreibgeschützt mit Prüfwert
 * (SHA-256). Jede Erzeugung steht im Änderungsprotokoll des Datensatzes (Ereignis „document“).
 *
 * Rechte: Erzeugen mit „Dokumente: erzeugen“ in der App der Tabelle und Leserecht auf die Tabelle; Ablegen zusätzlich
 * mit dem Recht, Datensätze der Tabelle zu bearbeiten.
 */
final class DocumentGenerator
{
    public const string PERMISSION = 'documents.generate';

    public const string EVENT = 'document';

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly RecordFiles $files,
    ) {}

    /**
     * Aktive Vorlagen einer Tabelle, mit denen die Person Dokumente erzeugen darf.
     *
     * @return Collection<int, DocumentTemplate>
     */
    public function templatesFor(CustomTable $table, ?User $user): Collection
    {
        if ($user === null || ! DataAccess::allows($user, $table, 'view')) {
            return collect();
        }

        return once(static fn (): Collection => DocumentTemplate::query()
            ->where('custom_table_id', $table->id)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(static fn (DocumentTemplate $template): bool => AppPermissions::can($user, self::PERMISSION, $template->app_id))
            ->each(static fn (DocumentTemplate $template) => $template->setRelation('customTable', $table))
            ->values());
    }

    /** Dateifeld der Vorlage für die Ablage, wenn es (noch) ein aktives Dateifeld der Grundtabelle ist. */
    public function storageField(DocumentTemplate $template, CustomTable $table): ?CustomField
    {
        $field = filled($template->file_field) ? TemplateFields::field($table, (string) $template->file_field) : null;

        return $field?->valueType() === FieldType::File ? $field : null;
    }

    /** Darf die Person das Dokument im Dateifeld ablegen (Bearbeiten-Recht, beschreibbare Tabelle)? */
    public function canStore(DocumentTemplate $template, CustomTable $table, DynamicRecord $record, ?User $user): bool
    {
        return $this->storageField($template, $table) !== null
            && ! $record->trashed()
            && $table->supports('update')
            && DataAccess::allows($user, $table, 'update');
    }

    /**
     * @return array{document: RenderedDocument, sha256: string, file: ?RecordFile}
     *
     * @throws BusinessRuleException wenn Ablegen gewünscht, aber nicht erlaubt oder das Dateifeld voll ist
     */
    public function generate(DocumentTemplate $template, DynamicRecord $record, ?User $user, bool $store): array
    {
        $table = $template->customTable ?? CustomTable::query()->findOrFail($template->custom_table_id);

        if (! $this->templatesFor($table, $user)->contains('id', $template->id)) {
            throw new BusinessRuleException(__('documents.generate.not_allowed'), 'documents.not_allowed');
        }

        $document = $this->renderer->render($template, $record, $user);
        $sha256 = hash('sha256', $document->pdf);
        $file = null;

        if ($store) {
            if (! $this->canStore($template, $table, $record, $user)) {
                throw new BusinessRuleException(__('documents.generate.store_not_allowed'), 'documents.store_not_allowed');
            }

            $file = $this->store($template, $table, $record, $document, $sha256);
        }

        activity('data')
            ->causedBy($user)
            ->performedOn($record)
            ->event(self::EVENT)
            ->withChanges(['attributes' => array_filter([
                'vorlage' => $template->name,
                'datei' => $file?->name ?? $document->fileName,
                'sha256' => $sha256,
                'abgelegt' => $file !== null ? (string) $this->storageField($template, $table)?->label : null,
            ], static fn (mixed $value): bool => $value !== null)])
            ->log(self::EVENT);

        return ['document' => $document, 'sha256' => $sha256, 'file' => $file];
    }

    /** Als neue Datei ins Dateifeld legen und den Datensatz speichern (Protokoll: Änderung des Dateifelds). */
    private function store(DocumentTemplate $template, CustomTable $table, DynamicRecord $record, RenderedDocument $document, string $sha256): RecordFile
    {
        $field = $this->storageField($template, $table);
        $references = array_values(array_filter((array) (RecordFiles::references($record->getAttribute($field->name)) ?? []), 'is_array'));
        $max = (int) ($field->optionsArray()['max_files'] ?? 0);

        if ($max > 0 && count($references) >= $max) {
            throw new BusinessRuleException(__('documents.generate.field_full', ['field' => $field->label, 'max' => $max]), 'documents.field_full');
        }

        $name = self::versionedName($document->fileName, array_column($references, 'name'));
        $directory = trim((string) config('dynamic_data.files.directory', 'files'), '/').'/'.$table->name.'/'.$field->name;
        $path = $directory.'/'.Str::uuid()->toString().'.pdf';
        $this->files->disk()->put($path, $document->pdf, ['visibility' => 'private']);

        $file = RecordFile::query()->create([
            'id' => (string) Str::uuid(),
            'custom_table_id' => $table->id,
            'field' => $field->name,
            'record_id' => (string) $record->getKey(),
            'disk' => $this->files->diskName(),
            'path' => $path,
            'name' => $name,
            'mime' => 'application/pdf',
            'size' => strlen($document->pdf),
            'uploaded_by' => auth()->id(),
            'document_template_id' => $template->id,
            'sha256' => $sha256,
        ]);

        $record->setAttribute($field->name, [...$references, $file->toReference()]);
        $record->save();

        return $file;
    }

    /**
     * Neue Fassung neben gleichnamigen: „Rechnung-1.pdf“, „Rechnung-1 (2).pdf“, …
     *
     * @param  list<string>  $existing
     */
    public static function versionedName(string $name, array $existing): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $candidate = $name;

        for ($n = 2; in_array($candidate, $existing, true); $n++) {
            $candidate = $base.' ('.$n.').pdf';
        }

        return $candidate;
    }
}
