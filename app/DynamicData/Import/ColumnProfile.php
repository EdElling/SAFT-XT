<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\DynamicData\Schema\FieldType;
use DateInterval;
use DateTimeInterface;

/**
 * Sammelt beim Lesen einer Spalte, welche Typen alle Werte erlauben, und leitet daraus den
 * Feldtyp ab. Nur wenn jeder nicht leere Wert passt, wird es ein Zahlen-, Datums- oder
 * Ja/Nein-Feld; sonst Text.
 */
final class ColumnProfile
{
    /** Textlängen werden auf diese Stufen aufgerundet, damit etwas längere Werte später passen. */
    private const array STRING_LENGTHS = [50, 100, 255];

    public int $filled = 0;

    public int $empty = 0;

    private bool $boolean = true;

    private bool $date = true;

    private bool $time = false;

    /** @var array<string, array{ok: bool, digits: int, scale: int}> */
    private array $numbers = [
        ImportValueParser::FORMAT_DE => ['ok' => true, 'digits' => 0, 'scale' => 0],
        ImportValueParser::FORMAT_EN => ['ok' => true, 'digits' => 0, 'scale' => 0],
    ];

    private bool $leadingZero = false;

    /** Alle Zahlen vierstellig ohne Nachkommastellen (1000–9999) – zusammen mit dem Titel ein Jahr. */
    private bool $yearLike = true;

    private int $maxLength = 0;

    private bool $multiline = false;

    public function add(mixed $value): void
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            $this->empty++;

            return;
        }

        $this->filled++;
        $text = ImportValueParser::text($value);
        $this->maxLength = max($this->maxLength, mb_strlen($text));
        $this->multiline = $this->multiline || str_contains($text, "\n");

        match (true) {
            is_bool($value) => $this->observe(boolean: true),
            is_int($value) => $this->observe(number: ImportValueParser::number((string) $value, ImportValueParser::FORMAT_EN)),
            is_float($value) => $this->observe(number: ImportValueParser::float($value)),
            $value instanceof DateTimeInterface => $this->observe(date: ImportValueParser::dateTime($value)),
            $value instanceof DateInterval => $this->observe(),
            default => $this->observeText($text),
        };
    }

    private function observeText(string $text): void
    {
        $this->boolean = $this->boolean && ImportValueParser::boolean($text) !== null;

        $date = ImportValueParser::date($text);
        $this->date = $this->date && $date !== null;
        $this->time = $this->time || ($date['time'] ?? false);

        foreach ($this->numbers as $format => $state) {
            $this->addNumber($format, ImportValueParser::number($text, $format));
        }
    }

    /**
     * Ein typisierter Wert (Excel-Zelle): er passt nur zu seinem eigenen Typ.
     *
     * @param  array{value: string, digits: int, scale: int, leading_zero: bool}|null  $number
     * @param  array{value: string, time: bool}|null  $date
     */
    private function observe(bool $boolean = false, ?array $number = null, ?array $date = null): void
    {
        $this->boolean = $this->boolean && $boolean;
        $this->date = $this->date && $date !== null;
        $this->time = $this->time || ($date['time'] ?? false);

        foreach ($this->numbers as $format => $state) {
            $this->addNumber($format, $number);
        }
    }

    /** @param  array{value: string, digits: int, scale: int, leading_zero: bool}|null  $number */
    private function addNumber(string $format, ?array $number): void
    {
        if ($number === null) {
            $this->numbers[$format]['ok'] = false;

            return;
        }

        $this->leadingZero = $this->leadingZero || $number['leading_zero'];
        $this->yearLike = $this->yearLike && $number['digits'] === 4 && $number['scale'] === 0 && ! str_starts_with($number['value'], '-');
        $this->numbers[$format]['digits'] = max($this->numbers[$format]['digits'], $number['digits']);
        $this->numbers[$format]['scale'] = max($this->numbers[$format]['scale'], $number['scale']);
    }

    /**
     * Feldtyp mit Optionen und Hinweisen.
     *
     * @param  string  $preferredFormat  Zahlenformat, wenn beide passen (aus dem CSV-Trennzeichen)
     * @return array{type: FieldType, options: array<string, int>, number_format: string|null, notes: list<string>}
     */
    /** Sieht die Spalte nach Jahreszahlen aus (für Titel wie „Jahr“)? */
    public function looksLikeYears(): bool
    {
        return $this->filled > 0 && $this->yearLike && $this->numbers[ImportValueParser::FORMAT_EN]['ok'];
    }

    public function guess(string $preferredFormat): array
    {
        if ($this->filled === 0) {
            return $this->result(FieldType::String, ['length' => self::STRING_LENGTHS[0]], notes: ['empty_column']);
        }

        if ($this->boolean) {
            return $this->result(FieldType::Boolean);
        }

        if ($this->date) {
            return $this->result($this->time ? FieldType::DateTime : FieldType::Date);
        }

        $formats = array_keys(array_filter($this->numbers, fn (array $state): bool => $state['ok']));

        if ($formats !== [] && $this->leadingZero) {
            return $this->text(['leading_zeros']);
        }

        if ($formats !== []) {
            $format = in_array($preferredFormat, $formats, true) ? $preferredFormat : $formats[0];
            ['digits' => $digits, 'scale' => $scale] = $this->numbers[$format];
            $notes = count($formats) > 1 && $this->numbers[ImportValueParser::FORMAT_DE] != $this->numbers[ImportValueParser::FORMAT_EN]
                ? ['number_format_'.$format]
                : [];

            if ($scale === 0 && $digits <= ImportValueParser::MAX_INTEGER_DIGITS) {
                return $this->result(FieldType::Integer, numberFormat: $format, notes: $notes);
            }

            if ($scale > 0 && $digits + $scale <= ImportValueParser::MAX_DECIMAL_PRECISION) {
                // Etwas Luft nach oben, mindestens die Vorgabe des Table-Designers (12 Stellen).
                $precision = min(ImportValueParser::MAX_DECIMAL_PRECISION, max(12, $digits + $scale + 2));

                return $this->result(FieldType::Decimal, ['precision' => $precision, 'scale' => $scale], $format, $notes);
            }

            return $this->text(['number_too_long']);
        }

        return $this->text();
    }

    /**
     * @param  list<string>  $notes
     * @return array{type: FieldType, options: array<string, int>, number_format: string|null, notes: list<string>}
     */
    private function text(array $notes = []): array
    {
        if ($this->multiline || $this->maxLength > self::STRING_LENGTHS[array_key_last(self::STRING_LENGTHS)]) {
            return $this->result(FieldType::Text, notes: $notes);
        }

        foreach (self::STRING_LENGTHS as $length) {
            if ($this->maxLength <= $length) {
                return $this->result(FieldType::String, ['length' => $length], notes: $notes);
            }
        }

        return $this->result(FieldType::Text, notes: $notes);
    }

    /**
     * @param  array<string, int>  $options
     * @param  list<string>  $notes
     * @return array{type: FieldType, options: array<string, int>, number_format: string|null, notes: list<string>}
     */
    private function result(FieldType $type, array $options = [], ?string $numberFormat = null, array $notes = []): array
    {
        return ['type' => $type, 'options' => $options, 'number_format' => $numberFormat, 'notes' => $notes];
    }
}
