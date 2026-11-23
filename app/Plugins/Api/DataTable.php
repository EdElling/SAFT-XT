<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\DynamicData\DataAccess;
use App\DynamicData\Files\FileKinds;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicFormBuilder;
use App\DynamicData\Validation\RecordChecker;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Eine Tabelle für Plugins (über Saft::table()). Jede Methode prüft die Datenrechte der angemeldeten Person –
 * ausser nach withoutAuthorization() für Hintergrundarbeit. Anlegen und Ändern prüfen dieselben Regeln wie die
 * Erfassung in SAFT und schreiben ins Änderungsprotokoll; berechnete Felder werden berechnet. Schreiben ist nur in
 * Tabellen des Table-Designers möglich, angebundene Tabellen sind über die Schnittstelle nur lesbar.
 *
 * @api
 */
final class DataTable
{
    private bool $authorizing = true;

    /** @internal Plugins erhalten Tabellen über Saft::table(). */
    public function __construct(private readonly CustomTable $table) {}

    /** Kopie ohne Rechteprüfung, z. B. für geplante Aufgaben oder Ereignisse. Bewusst einsetzen. */
    public function withoutAuthorization(): self
    {
        $copy = clone $this;
        $copy->authorizing = false;

        return $copy;
    }

    public function name(): string
    {
        return $this->table->name;
    }

    /** Bezeichnung in der aktuellen Sprache. */
    public function label(): string
    {
        return (string) $this->table->label;
    }

    /** @return list<string> technische Namen der aktiven Felder (inkl. berechneter) */
    public function fieldNames(): array
    {
        return $this->table->fields->where('is_active', true)->sortBy('sort_order')->pluck('name')->values()->all();
    }

    /** Hat die angemeldete Person dieses Datenrecht (view, create, update, delete, export, import)? */
    public function can(string $action): bool
    {
        return DataAccess::current($this->table, $action);
    }

    /** @throws AuthorizationException ohne das Datenrecht */
    public function authorize(string $action): void
    {
        if ($this->authorizing && ! $this->can($action)) {
            throw new AuthorizationException(__('plugins.api.forbidden', ['table' => $this->label(), 'action' => __('roles.actions.'.$action)]));
        }
    }

    /**
     * Adresse der Datenansicht dieser Tabelle; mit Datensatz ist er dort ausgewählt (Kind-Datensätze darunter). Prüft
     * keine Rechte – die Datenansicht selbst tut es beim Aufruf. Seit API 1.9.
     */
    public function url(DynamicRecord|int|string|null $record = null): string
    {
        return DynamicDataPage::urlFor($this->table->name, $record instanceof DynamicRecord ? $record->getKey() : $record);
    }

    /** Abfrage über alle Datensätze (ohne Papierkorb). */
    public function query(): Builder
    {
        $this->authorize('view');

        return DynamicRecord::forTable($this->table)->newQuery();
    }

    public function find(int|string $id): ?DynamicRecord
    {
        return $this->query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $values  Feldname => Wert (Verknüpfung: Schlüssel des Datensatzes, Auswahl: gespeicherter Wert)
     *
     * @throws ValidationException bei Verstössen gegen die Regeln des Table-Designers
     * @throws RecordRejected wenn ein Plugin die Änderung ablehnt
     */
    public function create(array $values): DynamicRecord
    {
        $this->authorize('create');
        $this->assertWritable();
        $this->validate($values);

        return app(RecordWriter::class)->create($this->table, $values, WriteOptions::plugin());
    }

    /**
     * @param  array<string, mixed>  $values  nur die zu ändernden Felder
     *
     * @throws ValidationException|RecordRejected
     */
    public function update(DynamicRecord|int|string $record, array $values): DynamicRecord
    {
        $this->authorize('update');
        $this->assertWritable();
        $record = $this->resolve($record);
        $this->validate($values, $record);

        return app(RecordWriter::class)->update($record, $values, WriteOptions::plugin());
    }

    /** @throws RecordRejected */
    public function delete(DynamicRecord|int|string $record): void
    {
        $this->authorize('delete');
        $this->assertWritable();
        app(RecordWriter::class)->delete($this->resolve($record), WriteOptions::plugin());
    }

    /**
     * Prüft Werte wie beim Erfassen, ohne zu speichern.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException Meldungen je Feldname, Text mit Bezeichnung des Felds
     */
    public function validate(array $values, ?DynamicRecord $existing = null): void
    {
        $writable = array_map(fn (CustomField $f): string => $f->name, app(RecordChecker::class)->fields($this->table));
        $messages = [];

        foreach (array_diff(array_keys($values), $writable) as $unknown) {
            $messages[$unknown][] = __('plugins.api.unknown_field', ['field' => $unknown, 'table' => $this->label()]);
        }

        foreach (app(RecordChecker::class)->check($this->table, $values, $existing, complete: true, references: true) as [$field, $message]) {
            $messages[$field?->name ?? $this->table->name][] = $field === null ? $message : $field->label.': '.$message;
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Formularfelder aus dem Table-Designer (Bezeichnung, Prüfregeln, Auswahllisten, Verknüpfungen) für eigene
     * Masken. Der Name jedes Felds ist der technische Name.
     *
     * @param  list<string>  $only  nur diese Felder (leer = alle)
     * @param  list<string>  $except  diese Felder nicht
     * @return list<Field>
     */
    public function formFields(array $only = [], array $except = []): array
    {
        $skip = $only === [] ? $except : [...$except, ...array_diff($this->table->fields->pluck('name')->all(), $only)];

        return app(DynamicFormBuilder::class)->components($this->table, array_values(array_unique($skip)));
    }

    /**
     * Dateien eines Felds vom Typ „Datei“ in der gespeicherten Reihenfolge (seit API 1.8).
     *
     * @return list<StoredFile>
     */
    public function files(DynamicRecord|int|string $record, string $field): array
    {
        $this->authorize('view');
        $this->fileField($field);
        $ids = RecordFiles::ids($this->current($record)->getAttribute($field));
        $files = RecordFile::query()->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(static fn (string $id): ?StoredFile => $files->has($id) ? StoredFile::from($files[$id]) : null, $ids)));
    }

    /**
     * Datei an ein Feld vom Typ „Datei“ anhängen – mit denselben Prüfungen wie im Formular (Dateiart nach Inhalt,
     * Höchstgrösse, Anzahl) und Eintrag im Änderungsprotokoll (seit API 1.8).
     *
     * @param  UploadedFile|string  $file  hochgeladene Datei oder Pfad auf dem Server
     * @param  string|null  $name  angezeigter Dateiname (Vorgabe: Name der Datei)
     *
     * @throws ValidationException bei nicht erlaubter Art, zu grosser Datei oder zu vielen Dateien
     */
    public function attachFile(DynamicRecord|int|string $record, string $field, UploadedFile|string $file, ?string $name = null): StoredFile
    {
        $this->authorize('update');
        $this->assertWritable();
        $definition = $this->fileField($field);
        $record = $this->current($record);

        $upload = match (true) {
            ! $file instanceof UploadedFile => new UploadedFile($file, $name ?? basename($file), null, null, true),
            $name !== null => new UploadedFile((string) $file->getRealPath(), $name, null, null, true),
            default => $file,
        };
        $options = $definition->optionsArray();
        $mimes = FileKinds::mimes((array) ($options['kinds'] ?? []));
        $references = (array) ($record->getAttribute($field) ?? []);
        $label = (string) $definition->label;

        $message = match (true) {
            ! is_file($upload->getRealPath() ?: '') => __('plugins.api.file_missing', ['field' => $label]),
            $mimes !== [] && ! in_array($upload->getMimeType(), $mimes, true) => __('plugins.api.file_kind', ['field' => $label, 'mime' => (string) $upload->getMimeType()]),
            $upload->getSize() > (int) ($options['max_mb'] ?? 10) * 1024 * 1024 => __('plugins.api.file_size', ['field' => $label, 'max' => (int) ($options['max_mb'] ?? 10)]),
            count($references) >= (int) ($options['max_files'] ?? 1) => __('plugins.api.file_count', ['field' => $label, 'max' => (int) ($options['max_files'] ?? 1)]),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages([$field => [$message]]);
        }

        $stored = app(RecordFiles::class)->store($this->table, $definition, $upload);
        app(RecordWriter::class)->update($record, [$field => [...$references, $stored->toReference()]], WriteOptions::plugin());

        return StoredFile::from($stored->fresh() ?? $stored);
    }

    /** Datei aus einem Feld vom Typ „Datei“ entfernen; sie wird gelöscht (seit API 1.8). */
    public function detachFile(DynamicRecord|int|string $record, string $field, string $fileId): void
    {
        $this->authorize('update');
        $this->assertWritable();
        $this->fileField($field);
        $record = $this->current($record);
        $references = array_values(array_filter(
            (array) ($record->getAttribute($field) ?? []),
            static fn (mixed $item): bool => ! is_array($item) || ($item['id'] ?? null) !== $fileId,
        ));

        app(RecordWriter::class)->update($record, [$field => $references === [] ? null : $references], WriteOptions::plugin());
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return app(RecordWriter::class)->batch($callback, WriteOptions::plugin());
    }

    private function resolve(DynamicRecord|int|string $record): DynamicRecord
    {
        if ($record instanceof DynamicRecord) {
            return $record;
        }

        return DynamicRecord::forTable($this->table)->newQuery()->findOrFail($record);
    }

    /** Aktueller Stand des Datensatzes (ein übergebenes Objekt wird aufgefrischt), damit keine Datei verloren geht. */
    private function current(DynamicRecord|int|string $record): DynamicRecord
    {
        return $record instanceof DynamicRecord ? $record->refresh() : $this->resolve($record);
    }

    private function fileField(string $name): CustomField
    {
        $field = $this->table->fields->first(static fn (CustomField $f): bool => $f->name === $name && $f->is_active);

        if ($field === null || $field->type !== FieldType::File) {
            throw new BusinessRuleException(__('plugins.api.not_a_file_field', ['field' => $name, 'table' => $this->label()]));
        }

        return $field;
    }

    private function assertWritable(): void
    {
        if ($this->table->isExternal()) {
            throw new BusinessRuleException(__('plugins.api.read_only', ['table' => $this->label()]));
        }
    }
}
