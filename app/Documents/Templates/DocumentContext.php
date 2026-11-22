<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\Documents\Letterhead;
use App\DynamicData\Schema\FieldType;
use ArrayAccess;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use IntlDateFormatter;

/**
 * Oberste Ebene einer Vorlage: die Felder des Datensatzes, dazu {{absender.…}}, {{heute}} und die Formatierungen
 * ({{betrag | CHF}}). Mustache sucht Formatierungen wie Platzhalter; sie sind gross geschrieben und kollidieren so nie
 * mit technischen Feldnamen (immer klein).
 *
 * @implements ArrayAccess<string, mixed>
 */
final class DocumentContext implements ArrayAccess
{
    /** Platzhalter für einen Zeilenumbruch aus „| Zeilen“ – übersteht das Maskieren, wird danach zu <br>. */
    public const string LINE_BREAK = "\u{E000}";

    public function __construct(
        private readonly RecordContext $record,
        private readonly Letterhead $letterhead,
        private readonly string $locale,
    ) {}

    public function offsetExists(mixed $offset): bool
    {
        $name = (string) $offset;

        return $this->record->offsetExists($name) || in_array($name, [TemplateFields::SENDER, TemplateFields::TODAY], true) || TemplateFields::isFilter($name);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $name = (string) $offset;

        return match (true) {
            $this->record->offsetExists($name) => $this->record[$name],
            $name === TemplateFields::SENDER => array_map(static fn (?string $value): ?string => filled($value) ? $value : null, $this->letterhead->sender),
            $name === TemplateFields::TODAY => new FieldValue(now()->toDateString(), FieldType::Date, [], $this->locale),
            default => $this->filter($name),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    private function filter(string $name): ?Closure
    {
        $locale = FieldValue::numberLocale($this->locale);
        $raw = static fn (mixed $value): mixed => $value instanceof FieldValue ? $value->raw : (is_scalar($value) ? $value : null);
        $number = static fn (mixed $value, ?int $digits) => is_numeric($raw($value))
            ? (string) Number::format((float) $raw($value), $digits, $digits === null ? 3 : null, $locale)
            : (string) $value;
        $lang = $this->locale;
        $date = static function (mixed $value, ?string $kind) use ($raw, $lang): string {
            if (blank($raw($value))) {
                return '';
            }

            try {
                $carbon = Carbon::parse((string) $raw($value));
            } catch (\Throwable) {
                return (string) $value;
            }

            return $kind === null
                ? $carbon->format(FieldValue::dateFormat($lang, 'date'))
                : (string) (new IntlDateFormatter($lang.'_CH', IntlDateFormatter::LONG, IntlDateFormatter::NONE))->format($carbon);
        };

        if (preg_match('/^Zahl:(\d)$/', $name, $m) === 1) {
            return static fn (mixed $value): string => $number($value, (int) $m[1]);
        }

        return match ($name) {
            'CHF' => static fn (mixed $value): string => $number($value, 2),
            'Zahl' => static fn (mixed $value): string => $number($value, null),
            'Datum' => static fn (mixed $value): string => $date($value, null),
            'DatumLang' => static fn (mixed $value): string => $date($value, 'long'),
            'Zeilen' => static fn (mixed $value): string => (string) preg_replace('/\R/u', self::LINE_BREAK, (string) $value),
            'Roh' => static fn (mixed $value): string => (string) $raw($value),
            'Gross' => static fn (mixed $value): string => mb_strtoupper((string) $value),
            default => null,
        };
    }
}
