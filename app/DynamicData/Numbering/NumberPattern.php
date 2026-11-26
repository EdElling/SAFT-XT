<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonInterface;

/**
 * Muster eines Nummernkreises (B-135), z. B. „R-{YYYY}-{0000}“: prüfen, Periode bestimmen, formatieren und eine
 * vorhandene Nummer zurücklesen. Platzhalter sind sprachneutral; {JJJJ}/{JJ} gelten als Alias für {YYYY}/{YY}.
 * Dieselbe Prüfung gilt im Table-Designer, in der App-Definition und für Vorschläge der KI.
 */
final readonly class NumberPattern
{
    /** Optionen am Feld vom Typ Nummernkreis. */
    public const string PATTERN = 'pattern';

    public const string RESET = 'reset';

    public const string DATE_FIELD = 'date_field';

    public const string FISCAL_START = 'fiscal_start';

    public const string START = 'start';

    /** Neubeginn des Zählers. */
    public const array RESETS = ['never', 'yearly', 'monthly'];

    /** Höchstlänge einer Nummer nach dem Muster (laufende Nummer in ihrer Breite). */
    public const int MAX_RESULT = 50;

    /** Spaltenlänge: Platz für Überlauf der laufenden Nummer über ihre Breite. */
    public const int COLUMN_LENGTH = 60;

    public const int MAX_WIDTH = 12;

    /** Periode ohne Neubeginn. */
    public const string NO_PERIOD = '-';

    /** Breite, mit der {#} in die Höchstlänge eingeht. */
    private const int UNPADDED_WIDTH = 6;

    private const array ALIASES = ['{JJJJ}' => '{YYYY}', '{JJ}' => '{YY}'];

    /**
     * @param  list<array{0: 'literal'|'year4'|'year2'|'month'|'counter', 1: string|int}>  $tokens  [literal, Text] | [year4|year2|month, ''] | [counter, Breite (0 = ohne Auffüllen)]
     */
    private function __construct(
        public string $pattern,
        public string $reset,
        public int $fiscalStart,
        public int $start,
        private array $tokens,
    ) {}

    /**
     * @throws BusinessRuleException mit Code dynamic.invalid_field
     */
    public static function make(string $pattern, string $reset = 'never', int $fiscalStart = 1, int $start = 1): self
    {
        $pattern = strtr(trim($pattern), self::ALIASES);

        if (! in_array($reset, self::RESETS, true)) {
            self::fail(__('numbering.errors.reset', ['reset' => $reset]));
        }

        if ($fiscalStart < 1 || $fiscalStart > 12) {
            self::fail(__('numbering.errors.fiscal_start'));
        }

        if ($start < 1) {
            self::fail(__('numbering.errors.start'));
        }

        $tokens = self::tokenize($pattern);
        $kinds = array_column($tokens, 0);

        if (count(array_keys($kinds, 'counter', true)) !== 1) {
            self::fail(__('numbering.errors.counter', ['pattern' => $pattern]));
        }

        $hasYear = in_array('year4', $kinds, true) || in_array('year2', $kinds, true);

        if (($reset === 'yearly' && ! $hasYear) || ($reset === 'monthly' && (! $hasYear || ! in_array('month', $kinds, true)))) {
            self::fail(__("numbering.errors.needs_{$reset}", ['pattern' => $pattern]));
        }

        $instance = new self($pattern, $reset, $fiscalStart, $start, $tokens);

        if ($instance->maxLength() > self::MAX_RESULT) {
            self::fail(__('numbering.errors.too_long', ['pattern' => $pattern, 'max' => self::MAX_RESULT]));
        }

        return $instance;
    }

    /**
     * Aus den Optionen eines Felds (bereits geprüft gespeichert).
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options): self
    {
        return self::make(
            (string) ($options[self::PATTERN] ?? ''),
            (string) ($options[self::RESET] ?? 'never'),
            (int) ($options[self::FISCAL_START] ?? 1),
            (int) ($options[self::START] ?? 1),
        );
    }

    /**
     * Periode des Zählers zum Bezugsdatum und die Jahres- bzw. Monatszahl, die das Muster zeigt. Bei jährlichem
     * Neubeginn mit Geschäftsjahr ist das Jahr das, in dem das Geschäftsjahr beginnt.
     *
     * @return array{key: string, year: int, month: int}
     */
    public function period(CarbonInterface $date): array
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        return match ($this->reset) {
            'yearly' => ['key' => (string) ($fiscal = $month >= $this->fiscalStart ? $year : $year - 1), 'year' => $fiscal, 'month' => $month],
            'monthly' => ['key' => sprintf('%04d-%02d', $year, $month), 'year' => $year, 'month' => $month],
            default => ['key' => self::NO_PERIOD, 'year' => $year, 'month' => $month],
        };
    }

    public function format(int $number, int $year, int $month): string
    {
        $result = '';

        foreach ($this->tokens as [$kind, $value]) {
            $result .= match ($kind) {
                'literal' => (string) $value,
                'year4' => sprintf('%04d', $year),
                'year2' => sprintf('%02d', $year % 100),
                'month' => sprintf('%02d', $month),
                'counter' => str_pad((string) $number, (int) $value, '0', STR_PAD_LEFT),
            };
        }

        return $result;
    }

    /**
     * Eine vorhandene Nummer zurücklesen (Import, Umstellen): Periode und laufende Nummer, oder null, wenn sie nicht
     * genau so aus dem Muster entstanden sein kann.
     *
     * @return array{key: string, number: int}|null
     */
    public function parse(string $value): ?array
    {
        $regex = '';

        foreach ($this->tokens as [$kind, $width]) {
            $regex .= match ($kind) {
                'literal' => preg_quote((string) $width, '/'),
                'year4' => '(?<year4>\d{4})',
                'year2' => '(?<year2>\d{2})',
                'month' => '(?<month>0[1-9]|1[0-2])',
                'counter' => '(?<counter>\d{'.max(1, (int) $width).',18})',
            };
        }

        if (preg_match('/^'.$regex.'$/', $value, $m) !== 1) {
            return null;
        }

        $year = isset($m['year4']) && $m['year4'] !== '' ? (int) $m['year4'] : (isset($m['year2']) && $m['year2'] !== '' ? 2000 + (int) $m['year2'] : 0);
        $month = isset($m['month']) && $m['month'] !== '' ? (int) $m['month'] : 1;
        $number = (int) $m['counter'];

        // Aufgefüllte Nummern genau so, wie das Muster sie schreibt (0042, nicht 042 oder 00042).
        if ($this->format($number, $year, $month) !== $value) {
            return null;
        }

        $key = match ($this->reset) {
            'yearly' => (string) $year,
            'monthly' => sprintf('%04d-%02d', $year, $month),
            default => self::NO_PERIOD,
        };

        return ['key' => $key, 'number' => $number];
    }

    /** Besteht das Muster nur aus der laufenden Nummer ({0000} bzw. {#})? Dann werden Ganzzahlen beim Umstellen formatiert. */
    public function isCounterOnly(): bool
    {
        return count($this->tokens) === 1 && $this->tokens[0][0] === 'counter';
    }

    /** Länge der Nummer mit der laufenden Nummer in ihrer Breite. */
    public function maxLength(): int
    {
        $length = 0;

        foreach ($this->tokens as [$kind, $value]) {
            $length += match ($kind) {
                'literal' => mb_strlen((string) $value),
                'year4' => 4,
                'year2', 'month' => 2,
                'counter' => (int) $value === 0 ? self::UNPADDED_WIDTH : (int) $value,
            };
        }

        return $length;
    }

    /**
     * @return list<array{0: 'literal'|'year4'|'year2'|'month'|'counter', 1: string|int}>
     */
    private static function tokenize(string $pattern): array
    {
        if ($pattern === '') {
            self::fail(__('numbering.errors.counter', ['pattern' => $pattern]));
        }

        $tokens = [];

        foreach (preg_split('/(\{[^{}]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (! str_starts_with($part, '{')) {
                if (preg_match('/^[A-Za-z0-9\-\/._ ]+$/', $part) !== 1) {
                    self::fail(__('numbering.errors.characters', ['pattern' => $pattern]));
                }

                $tokens[] = ['literal', $part];

                continue;
            }

            $tokens[] = match (true) {
                $part === '{YYYY}' => ['year4', ''],
                $part === '{YY}' => ['year2', ''],
                $part === '{MM}' => ['month', ''],
                $part === '{#}' => ['counter', 0],
                preg_match('/^\{(0{1,'.self::MAX_WIDTH.'})\}$/', $part, $m) === 1 => ['counter', strlen($m[1])],
                default => self::fail(__('numbering.errors.placeholder', ['placeholder' => $part])),
            };
        }

        return $tokens;
    }

    private static function fail(string $message): never
    {
        throw new BusinessRuleException($message, 'dynamic.invalid_field');
    }
}
