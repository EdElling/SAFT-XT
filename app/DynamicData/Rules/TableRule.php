<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use InvalidArgumentException;

/**
 * Regel auf Tabellenebene: Operand, Vergleich, Operand – z. B. „Ende ≥ Beginn“.
 * Herkunft: aus einer CHECK-Regel der Datenbank (Table-Connector) oder im Table-Designer angelegt.
 */
final readonly class TableRule
{
    public const array OPERATORS = ['=', '<>', '<', '<=', '>', '>='];

    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_DESIGNER = 'designer';

    /**
     * @param  bool  $whenFilled  nur prüfen, wenn beide Seiten gefüllt sind (wie CHECK in SQL)
     * @param  ?string  $constraint  Name der CHECK-Regel in der Datenbank
     * @param  array<string, string>|null  $message  eigene Meldung je Sprache (Table-Designer)
     */
    public function __construct(
        public Operand $left,
        public string $operator,
        public Operand $right,
        public bool $whenFilled = true,
        public string $source = self::SOURCE_DESIGNER,
        public ?string $constraint = null,
        public ?array $message = null,
    ) {
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unbekannter Vergleich: {$operator}");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Operand::fromArray((array) ($data['left'] ?? [])),
            (string) ($data['operator'] ?? ''),
            Operand::fromArray((array) ($data['right'] ?? [])),
            (bool) ($data['when_filled'] ?? true),
            (string) ($data['source'] ?? self::SOURCE_DESIGNER),
            isset($data['constraint']) ? (string) $data['constraint'] : null,
            is_array($data['message'] ?? null) && $data['message'] !== [] ? array_map('strval', $data['message']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'left' => $this->left->toArray(),
            'operator' => $this->operator,
            'right' => $this->right->toArray(),
            'when_filled' => $this->whenFilled,
            'source' => $this->source,
            'constraint' => $this->constraint,
            'message' => $this->message,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** Vergleich mit vertauschten Seiten, z. B. für die Meldung beim anderen Feld. */
    public static function mirror(string $operator): string
    {
        return match ($operator) {
            '<' => '>',
            '<=' => '>=',
            '>' => '<',
            '>=' => '<=',
            default => $operator,
        };
    }

    /**
     * @param  list<array<string, mixed>>|null  $stored
     * @return list<self>
     */
    public static function listFrom(?array $stored): array
    {
        $rules = [];

        foreach ((array) $stored as $data) {
            try {
                $rules[] = self::fromArray((array) $data);
            } catch (InvalidArgumentException) {
                // Unbrauchbare Einträge überspringen statt die ganze Tabelle zu blockieren.
            }
        }

        return $rules;
    }
}
