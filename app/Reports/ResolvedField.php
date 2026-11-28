<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\SystemFields;
use App\Models\CustomField;
use App\Models\CustomTable;

/** Ein Feld der Auswertung, aufgelöst: Tabelle, Verknüpfungspfad, Alias in der Abfrage. */
final readonly class ResolvedField
{
    /**
     * @param  list<CustomField>  $path  Verknüpfungsfelder ab der Grundtabelle
     * @param  array{alias: string, columns: list<string>, separator: string}|null  $display
     */
    public function __construct(
        public string $key,
        public CustomField $field,
        public CustomTable $table,
        public array $path,
        public string $alias,
        public ?string $bucket,
        public string $label,
        public ?array $display = null,
    ) {}

    /**
     * Verknüpfungsfeld in Zeilen, Spalten oder Liste: Anzeige über die Anzeigefelder des verknüpften Datensatzes
     * (gruppiert wird weiter nach dem Schlüssel).
     *
     * @param  array{alias: string, columns: list<string>, separator: string}  $display
     */
    public function withDisplay(array $display): self
    {
        return new self($this->key, $this->field, $this->table, $this->path, $this->alias, $this->bucket, $this->label, $display);
    }

    /** @return list<string> Aliase der Anzeigespalten in der Abfrage */
    public function displayKeys(): array
    {
        return array_map(fn (int $i): string => $this->key.'__d'.$i, array_keys($this->display['columns'] ?? []));
    }

    /** Gespeicherter Wertetyp (berechnete Felder: Ganz- bzw. Dezimalzahl). */
    public function type(): FieldType
    {
        return $this->field->valueType();
    }

    /** Angelegt bzw. geändert von (B-183): der Wert ist eine Benutzer-ID, angezeigt wird der Name. */
    public function isPerson(): bool
    {
        return SystemFields::isPerson($this->field);
    }

    public function isNumeric(): bool
    {
        return in_array($this->type(), [FieldType::Integer, FieldType::Decimal], true);
    }

    public function isTemporal(): bool
    {
        return in_array($this->type(), [FieldType::Date, FieldType::DateTime], true);
    }

    public function column(): string
    {
        return $this->alias.'.'.$this->field->name;
    }
}
