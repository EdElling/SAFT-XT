<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use DateInterval;
use DateTimeInterface;

/**
 * Erkennt und wandelt Werte aus Import-Dateien: Zahlen im deutschen oder englischen Format,
 * Ja/Nein, Datum und Datum mit Uhrzeit. Dieselben Regeln gelten für die Typerkennung und
 * später für das Einspielen, damit jeder Wert, der einen Typ begründet hat, auch passt.
 */
final class ImportValueParser
{
    public const string FORMAT_DE = 'de';

    public const string FORMAT_EN = 'en';

    /** Mehr Stellen passen nicht sicher in eine Ganzzahl (bigint). */
    public const int MAX_INTEGER_DIGITS = 18;

    public const int MAX_DECIMAL_PRECISION = 38;

    private const array NUMBER_PATTERNS = [
        // Tausendergruppen nur ohne führende Null; ohne Gruppen beliebig viele Ziffern.
        self::FORMAT_DE => '/^([+-]?)((?:[1-9]\d{0,2}(?:\.\d{3})+)|\d+)(?:,(\d+))?$/',
        self::FORMAT_EN => '/^([+-]?)((?:[1-9]\d{0,2}(?:,\d{3})+)|\d+)(?:\.(\d+))?$/',
    ];

    private const array TRUE_WORDS = ['ja', 'true', 'wahr', 'yes'];

    private const array FALSE_WORDS = ['nein', 'false', 'falsch', 'no'];

    /**
     * Zahl im angegebenen Format, normalisiert ("-1234.5"), mit Stellen vor und nach dem Komma.
     * null, wenn der Text keine Zahl in diesem Format ist.
     *
     * @return array{value: string, digits: int, scale: int, leading_zero: bool}|null
     */
    public static function number(string $text, string $format): ?array
    {
        if (preg_match(self::NUMBER_PATTERNS[$format], trim($text), $m) !== 1) {
            return null;
        }

        $integer = str_replace($format === self::FORMAT_DE ? '.' : ',', '', $m[2]);
        $fraction = $m[3] ?? '';
        $significant = ltrim($integer, '0');

        return [
            'value' => ($m[1] === '-' ? '-' : '').$integer.($fraction !== '' ? '.'.$fraction : ''),
            'digits' => max(1, strlen($significant)),
            'scale' => strlen($fraction),
            // "007" oder "01067" ist ein Code (PLZ, Artikelnummer), keine Zahl.
            'leading_zero' => strlen($integer) > 1 && $integer[0] === '0',
        ];
    }

    /**
     * Gleitkommazahl aus Excel als Dezimaltext ohne Rundungsrauschen (0.30000000000000004 -> 0.3).
     *
     * @return array{value: string, digits: int, scale: int, leading_zero: false}
     */
    public static function float(float $value): array
    {
        // 15 signifikante Stellen, unabhängig von der Systemsprache (sprintf %G nähme ein Dezimalkomma).
        $decimals = $value == 0.0 ? 0 : max(0, 14 - (int) floor(log10(abs($value))));
        $text = number_format(round($value, $decimals), $decimals, '.', '');
        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
        [$integer, $fraction] = array_pad(explode('.', ltrim($text, '-')), 2, '');

        return [
            'value' => $text === '-0' ? '0' : $text,
            'digits' => max(1, strlen(ltrim($integer, '0'))),
            'scale' => strlen($fraction),
            'leading_zero' => false,
        ];
    }

    public static function boolean(string $text): ?bool
    {
        $word = mb_strtolower(trim($text));

        return match (true) {
            in_array($word, self::TRUE_WORDS, true) => true,
            in_array($word, self::FALSE_WORDS, true) => false,
            default => null,
        };
    }

    /**
     * Datum (TT.MM.JJJJ oder JJJJ-MM-TT), optional mit Uhrzeit (HH:MM[:SS]).
     *
     * @return array{value: string, time: bool}|null
     */
    public static function date(string $text): ?array
    {
        $text = trim($text);
        $patterns = [
            '/^(?<d>\d{1,2})\.(?<m>\d{1,2})\.(?<y>\d{4})(?:\s+(?<h>\d{1,2}):(?<i>\d{2})(?::(?<s>\d{2}))?)?$/',
            '/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})(?:[ T](?<h>\d{2}):(?<i>\d{2})(?::(?<s>\d{2}))?)?$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }

            [$year, $month, $day] = [(int) $m['y'], (int) $m['m'], (int) $m['d']];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            if (($m['h'] ?? '') === '') {
                return ['value' => $date, 'time' => false];
            }

            [$hour, $minute, $second] = [(int) $m['h'], (int) $m['i'], (int) ($m['s'] ?? 0)];

            if ($hour > 23 || $minute > 59 || $second > 59) {
                return null;
            }

            return ['value' => sprintf('%s %02d:%02d:%02d', $date, $hour, $minute, $second), 'time' => true];
        }

        return null;
    }

    /** Datum aus Excel; Mitternacht zählt als reines Datum. */
    public static function dateTime(DateTimeInterface $value): array
    {
        $time = $value->format('H:i:s') !== '00:00:00';

        return ['value' => $value->format($time ? 'Y-m-d H:i:s' : 'Y-m-d'), 'time' => $time];
    }

    /** Text, wie er bei einer Textspalte gespeichert wird. */
    public static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value)['value'],
            $value instanceof DateTimeInterface => self::dateTime($value)['value'],
            $value instanceof DateInterval => $value->format('%H:%I:%S'),
            default => (string) $value,
        };
    }
}
