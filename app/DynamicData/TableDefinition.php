<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Schema\FieldType;
use App\ExternalData\ConnectionDriver;
use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Laufzeit-Definition einer Tabelle für DynamicRecord: Designer-Tabelle im Namensraum app_data
 * oder angebundene externe Tabelle bzw. View (Table-Connector).
 */
final readonly class TableDefinition
{
    /**
     * @param  list<string>  $fillable  aktive, beschreibbare Felder
     * @param  array<string, string>  $casts  alle Felder (auch inaktive, damit Daten lesbar bleiben)
     * @param  string  $keyName  Schlüsselspalte; bei Tabellen ohne einzelnen Schlüssel die erste Spalte (nur zum Lesen)
     * @param  bool  $systemColumns  deleted_at, Zeitstempel, created_by/updated_by (nur Designer-Tabellen)
     * @param  bool  $writable  Angebundene Tabellen nur, wenn Schreiben freigeschaltet ist.
     * @param  list<string>  $databaseDefaults  Spalten mit Vorgabewert der Datenbank (leer lassen statt NULL)
     * @param  list<string>  $compositeKey  Spalten eines zusammengesetzten Schlüssels (sonst leer)
     * @param  array<string, string|null>  $sequences  fortlaufend vorgeschlagene Felder => Zählbereich (Verknüpfung) oder null
     * @param  array<string, array{relation: string, table: string, source: string}>  $copies  Vorgaben aus dem Master-Datensatz
     * @param  array<string, array{ast: array<string, mixed>, scale: int}>  $computed  berechnete Felder in Rechenreihenfolge
     * @param  list<array{table: string, via: string}>  $dependents  Master-Tabellen, die über diese Tabelle summieren
     * @param  bool  $uuidKey  SAFT vergibt den UUID-Schlüssel beim Anlegen (MySQL meldet DEFAULT (uuid()) nicht zurück)
     * @param  list<string>  $files  Felder vom Typ „Datei“ (nach dem Speichern abgleichen, beim Löschen mitlöschen)
     * @param  array<string, array{id: int, options: array<string, mixed>}>  $numbers  Nummernkreise (B-135): Feld => Kennung und Optionen
     */
    public function __construct(
        public string $name,
        public string $connection,
        public string $qualifiedTable,
        public array $fillable,
        public array $casts,
        public string $keyName = 'id',
        public string $keyType = 'int',
        public bool $systemColumns = true,
        public bool $writable = true,
        public bool $hasKey = true,
        public bool $incrementing = true,
        public array $databaseDefaults = [],
        public array $compositeKey = [],
        public array $sequences = [],
        public array $copies = [],
        public array $computed = [],
        public array $dependents = [],
        public bool $uuidKey = false,
        public array $files = [],
        public array $numbers = [],
    ) {}

    public static function fromCustomTable(CustomTable $table, string $qualifiedTable): self
    {
        $fields = $table->relationLoaded('fields') ? $table->fields : $table->fields()->get();

        return new self(
            name: $table->name,
            connection: (string) config('dynamic_data.connection'),
            qualifiedTable: $qualifiedTable,
            fillable: $fields->where('is_active', true)->pluck('name')->values()->all(),
            casts: self::casts($fields),
            sequences: $fields->filter(fn (CustomField $f): bool => $f->is_active && SequenceSuggester::applies($f))
                ->mapWithKeys(fn (CustomField $f): array => [$f->name => SequenceSuggester::scope($f)])
                ->all(),
            copies: self::copies($fields),
            computed: self::computed($fields),
            dependents: Recalculator::dependentsOf($table->name),
            files: $fields->filter(fn (CustomField $f): bool => $f->type === FieldType::File)->pluck('name')->values()->all(),
            // Auch inaktive: eine vergebene Nummer bleibt geschützt; vergeben wird nur in aktiven Feldern.
            numbers: $fields->filter(fn (CustomField $f): bool => $f->type === FieldType::Number)
                ->mapWithKeys(fn (CustomField $f): array => [$f->name => ['id' => (int) $f->id, 'options' => [...$f->optionsArray(), 'active' => (bool) $f->is_active]]])
                ->all(),
        );
    }

    public static function fromExternalTable(CustomTable $table, string $connection): self
    {
        $fields = $table->relationLoaded('fields') ? $table->fields : $table->fields()->get();
        $key = $table->keyColumn();
        $keyField = $key === null ? null : $fields->firstWhere('name', $key);
        // MySQL, MariaDB und SQL Server liefern eine UUID-Vorgabe der Datenbank nach dem Einfügen nicht zurück
        // (Eloquent liest nur Auto-Increment bzw. Identity): SAFT vergibt die UUID selbst.
        $uuidKey = $keyField !== null && ! $keyField->is_generated
            && ($keyField->optionsArray()['db_uuid'] ?? false)
            && in_array($table->databaseConnection?->driver, [ConnectionDriver::Mysql, ConnectionDriver::Mariadb, ConnectionDriver::Sqlsrv], true);

        return new self(
            name: $table->name,
            connection: $connection,
            qualifiedTable: $table->external_schema.'.'.$table->external_table,
            fillable: $fields->filter(fn (CustomField $f): bool => self::isEditable($f) || self::isKeyInput($table, $f))->pluck('name')->values()->all(),
            casts: self::casts($fields),
            keyName: $key ?? (string) ($fields->first()?->name ?? 'id'),
            keyType: $keyField?->type === FieldType::Integer ? 'int' : 'string',
            systemColumns: false,
            writable: $table->isWritable(),
            hasKey: $key !== null,
            // Füllt die Datenbank den Schlüssel (Sequenz, Identity, Vorgabe wie gen_random_uuid()),
            // liest Eloquent ihn nach dem Einfügen zurück – außer bei einer UUID-Vorgabe unter MySQL und SQL Server.
            incrementing: $keyField !== null && self::filledByDatabase($keyField) && ! $uuidKey,
            databaseDefaults: $fields->filter(fn (CustomField $f): bool => ($f->optionsArray()['db_default'] ?? false))->pluck('name')->values()->all(),
            compositeKey: $table->hasCompositeKey() ? $table->keyColumns() : [],
            uuidKey: $uuidKey,
        );
    }

    /**
     * @param  iterable<CustomField>  $fields
     * @return array<string, array{ast: array<string, mixed>, scale: int}>
     */
    private static function computed(iterable $fields): array
    {
        try {
            $ordered = FormulaAnalyzer::order($fields);
        } catch (FormulaException) {
            // Kreisbezug wird beim Speichern der Formel abgelehnt; hier nichts rechnen statt abzubrechen.
            return [];
        }

        $computed = [];

        foreach ($ordered as $field) {
            $options = $field->optionsArray();
            $computed[$field->name] = [
                'ast' => (array) $options['ast'],
                'scale' => $field->valueType() === FieldType::Integer ? 0 : (int) ($options['scale'] ?? 2),
            ];
        }

        return $computed;
    }

    /**
     * @param  iterable<CustomField>  $fields
     * @return array<string, array{relation: string, table: string, source: string}>
     */
    private static function copies(iterable $fields): array
    {
        $byName = collect($fields)->keyBy('name');
        $copies = [];

        foreach ($byName as $field) {
            [$relation, $source] = CopySource::of($field) ?? [null, null];
            $table = $relation === null ? null : ($byName[$relation]?->optionsArray()['table'] ?? null);

            if ($field->is_active && is_string($table)) {
                $copies[$field->name] = ['relation' => (string) $relation, 'table' => $table, 'source' => (string) $source];
            }
        }

        return $copies;
    }

    /** Spalte einer angebundenen Tabelle, die SAFT schreiben darf. */
    public static function isEditable(CustomField $field): bool
    {
        return $field->is_active && ! $field->is_read_only && ! $field->is_generated && ! self::isTextOnly($field);
    }

    /**
     * Schlüsselspalte, die beim Anlegen eingegeben werden muss (die Datenbank füllt sie nicht), auch wenn
     * sie „Nur lesen“ ist: Nach dem Anlegen ist sie ohnehin fest (z. B. publikation_id einer Zuordnung).
     */
    public static function isKeyInput(CustomTable $table, CustomField $field): bool
    {
        return $table->isExternal()
            && $field->is_active
            && in_array($field->name, $table->keyColumns(), true)
            && ! self::filledByDatabase($field)
            && ! self::isTextOnly($field);
    }

    /**
     * Nur als Text anzeigbar (uuid, json, time …). Eine Verknüpfung über eine solche Spalte (z. B. uuid)
     * ist trotzdem beschreibbar: Ihr Wert kommt aus der Auswahl der verknüpften Tabelle.
     */
    public static function isTextOnly(CustomField $field): bool
    {
        return ($field->optionsArray()['text_only'] ?? false) && $field->type !== FieldType::Relation;
    }

    /** Die Datenbank füllt den Wert selbst, wenn er beim Anlegen fehlt. */
    public static function filledByDatabase(CustomField $field): bool
    {
        return $field->is_generated || ($field->optionsArray()['db_default'] ?? false);
    }

    /**
     * @param  iterable<CustomField>  $fields
     * @return array<string, string>
     */
    private static function casts(iterable $fields): array
    {
        $casts = [];

        foreach ($fields as $field) {
            $options = $field->optionsArray();
            // Verknüpfungen angebundener Tabellen behalten den Typ ihrer Spalte (z. B. uuid statt Zahl).
            $type = $field->type === FieldType::Relation && isset($options['base_type'])
                ? FieldType::from((string) $options['base_type'])
                : $field->type;

            if (($cast = $type->cast($options)) !== null) {
                $casts[$field->name] = $cast;
            }
        }

        return $casts;
    }
}
