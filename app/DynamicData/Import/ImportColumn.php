<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\DynamicData\Schema\FieldType;
use DateTimeInterface;

/** Eine Spalte der Import-Datei mit abgeleitetem Feldnamen und erkanntem Typ. */
final readonly class ImportColumn
{
    /**
     * @param  int  $index  0-basiert, Position in der Datei
     * @param  array<string, int>  $options  Feldoptionen (length bzw. precision/scale)
     * @param  list<string>  $notes  übersetzte Hinweise für die Vorschau
     */
    public function __construct(
        public int $index,
        public string $header,
        public string $name,
        public FieldType $type,
        public array $options = [],
        public ?string $numberFormat = null,
        public array $notes = [],
        public int $filled = 0,
        public int $empty = 0,
        public ?string $label = null,
    ) {}

    /**
     * Typen, die zu den Daten der Spalte passen: der erkannte, verwandte und Text.
     *
     * @return list<FieldType>
     */
    public function compatibleTypes(): array
    {
        return match ($this->type) {
            FieldType::Integer => [FieldType::Integer, FieldType::Decimal, FieldType::String, FieldType::Text],
            FieldType::Decimal => [FieldType::Decimal, FieldType::String, FieldType::Text],
            FieldType::Date => [FieldType::Date, FieldType::DateTime, FieldType::String, FieldType::Text],
            FieldType::DateTime => [FieldType::DateTime, FieldType::String, FieldType::Text],
            FieldType::Boolean => [FieldType::Boolean, FieldType::String, FieldType::Text],
            FieldType::String => [FieldType::String, FieldType::Text],
            default => [FieldType::Text],
        };
    }

    /** Die Spalte mit den Angaben aus der Vorschau (Name, Bezeichnung, Typ). */
    public function withChoice(string $name, string $label, FieldType $type): self
    {
        if (! in_array($type, $this->compatibleTypes(), true)) {
            $type = $this->type;
        }

        $options = match (true) {
            $type === $this->type => $this->options,
            $type === FieldType::String => ['length' => 255],
            $type === FieldType::Decimal => ['precision' => ImportValueParser::MAX_INTEGER_DIGITS + 2, 'scale' => 2],
            default => [],
        };

        return new self($this->index, $this->header, $name, $type, $options, $this->numberFormat, $this->notes, $this->filled, $this->empty, $label);
    }

    /** @return array<string, mixed> für Livewire (nur einfache Werte) */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'header' => $this->header,
            'name' => $this->name,
            'type' => $this->type->value,
            'options' => $this->options,
            'number_format' => $this->numberFormat,
            'notes' => $this->notes,
            'filled' => $this->filled,
            'empty' => $this->empty,
            'label' => $this->label,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['index'],
            (string) $data['header'],
            (string) $data['name'],
            FieldType::from((string) $data['type']),
            (array) ($data['options'] ?? []),
            $data['number_format'] ?? null,
            array_values((array) ($data['notes'] ?? [])),
            (int) ($data['filled'] ?? 0),
            (int) ($data['empty'] ?? 0),
            $data['label'] ?? null,
        );
    }

    /** Spaltenbuchstabe wie in Excel (A, B, …, AA). */
    public function letter(): string
    {
        return self::letterFor($this->index);
    }

    public static function letterFor(int $index): string
    {
        $letter = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    /**
     * Felddefinition für DynamicSchemaService::createTable().
     *
     * @return array<string, mixed>
     */
    public function toFieldDefinition(bool $sortable = true): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label ?? $this->header,
            'type' => $this->type->value,
            'options' => $this->options,
            'is_required' => false,
            'is_searchable' => in_array($this->type, [FieldType::String, FieldType::Text], true),
            'is_sortable' => $sortable && $this->type !== FieldType::Text,
        ];
    }

    /** Wert aus der Datei im Format des Feldes; null für leere Zellen. */
    public function convert(mixed $value): string|int|bool|null
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            FieldType::Boolean => is_bool($value) ? $value : ImportValueParser::boolean((string) $value),
            FieldType::Integer => ($number = $this->number($value)) === null ? null : (int) $number,
            FieldType::Decimal => $this->number($value),
            FieldType::Date, FieldType::DateTime => $value instanceof DateTimeInterface
                ? $value->format($this->type === FieldType::Date ? 'Y-m-d' : 'Y-m-d H:i:s')
                : $this->date((string) $value),
            default => ImportValueParser::text($value),
        };
    }

    private function number(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_float($value) => ImportValueParser::float($value)['value'],
            default => ImportValueParser::number((string) $value, $this->numberFormat ?? ImportValueParser::FORMAT_EN)['value'] ?? null,
        };
    }

    private function date(string $text): ?string
    {
        $date = ImportValueParser::date($text);

        if ($date === null) {
            return null;
        }

        return $this->type === FieldType::DateTime && ! $date['time'] ? $date['value'].' 00:00:00' : $date['value'];
    }
}
