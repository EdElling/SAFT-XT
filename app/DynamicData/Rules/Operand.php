<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use InvalidArgumentException;

/**
 * Baustein einer Regel: Feld desselben Datensatzes oder fester Wert.
 *
 * Gespeichert mit „kind“, damit spätere Arten (z. B. „aggregate“: Summe über Kind-Datensätze
 * für berechnete Felder im Table-Designer) ohne Umbau dazukommen können.
 */
final readonly class Operand
{
    public const string FIELD = 'field';

    public const string VALUE = 'value';

    private function __construct(
        public string $kind,
        public string|int|float|null $value,
    ) {}

    public static function field(string $name): self
    {
        return new self(self::FIELD, $name);
    }

    public static function value(string|int|float|null $value): self
    {
        return new self(self::VALUE, $value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return match ($data['kind'] ?? null) {
            self::FIELD => self::field((string) ($data['field'] ?? '')),
            self::VALUE => self::value(is_scalar($data['value'] ?? null) ? $data['value'] : null),
            default => throw new InvalidArgumentException('Unbekannte Operandenart.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->kind === self::FIELD
            ? ['kind' => self::FIELD, 'field' => $this->value]
            : ['kind' => self::VALUE, 'value' => $this->value];
    }

    public function isField(): bool
    {
        return $this->kind === self::FIELD;
    }

    public function fieldName(): ?string
    {
        return $this->isField() ? (string) $this->value : null;
    }
}
