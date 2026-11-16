<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\DynamicData\DataAccess;
use App\DynamicData\Ui\DynamicFormBuilder;
use App\DynamicData\Validation\RecordChecker;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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

        return DynamicRecord::forTable($this->table)->create($values);
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
        $record->update($values);

        return $record;
    }

    /** @throws RecordRejected */
    public function delete(DynamicRecord|int|string $record): void
    {
        $this->authorize('delete');
        $this->assertWritable();
        $this->resolve($record)->delete();
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
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return Saft::transaction($callback);
    }

    private function resolve(DynamicRecord|int|string $record): DynamicRecord
    {
        if ($record instanceof DynamicRecord) {
            return $record;
        }

        return DynamicRecord::forTable($this->table)->newQuery()->findOrFail($record);
    }

    private function assertWritable(): void
    {
        if ($this->table->isExternal()) {
            throw new BusinessRuleException(__('plugins.api.read_only', ['table' => $this->label()]));
        }
    }
}
