<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\Schema\FieldType;

/** Eine Spalte des Ergebnisses: Feld (Liste), Gruppe oder Wert. */
final readonly class ReportColumn
{
    public const string FIELD = 'field';

    public const string GROUP = 'group';

    public const string MEASURE = 'measure';

    /**
     * @param  array<string, string>|null  $display  angezeigte Namen eines Verknüpfungsfelds je Schlüssel
     * @param  int|null  $scale  Nachkommastellen einer Formel
     * @param  bool  $computed  berechneter Wert bzw. berechnete Listenspalte
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $role,
        public ?ResolvedField $field = null,
        public ?string $aggregate = null,
        public ?array $display = null,
        public ?int $scale = null,
        public bool $computed = false,
    ) {}

    /**
     * Dieselbe Spalte mit den angezeigten Namen eines Verknüpfungsfelds (Schlüssel → Name).
     *
     * @param  array<string, string>  $display
     */
    public function withDisplay(array $display): self
    {
        return new self($this->key, $this->label, $this->role, $this->field, $this->aggregate, $display, $this->scale, $this->computed);
    }

    /** Wertetyp der Spalte; Anzahl ist eine Ganzzahl, Datums-Stufen sind Text. */
    public function type(): FieldType
    {
        return match (true) {
            // Formel: immer Dezimalzahl mit den gewählten Nachkommastellen.
            $this->computed => FieldType::Decimal,
            $this->display !== null && $this->aggregate === null => FieldType::String,
            in_array($this->aggregate, ['count', 'count_distinct'], true) => FieldType::Integer,
            $this->aggregate === 'avg' => FieldType::Decimal,
            $this->field?->bucket !== null && $this->aggregate === null => FieldType::String,
            default => $this->field?->type() ?? FieldType::String,
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this->type(), [FieldType::Integer, FieldType::Decimal], true);
    }
}
