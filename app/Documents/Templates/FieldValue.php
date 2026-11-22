<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Support\Translations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Stringable;

/**
 * Ein Feldwert in einer Vorlage: als Text wie in SAFT angezeigt (Zahl mit Tausendertrennung, Datum, Bezeichnung des
 * Auswahlwerts) – in der Sprache des Dokuments. Formatierungen (TemplateEngine) greifen auf den gespeicherten Wert zu.
 * Leere Werte und „nein“ gibt es nicht als FieldValue (sondern null bzw. false), damit Abschnitte {{#feld}} sie als leer
 * erkennen.
 */
final readonly class FieldValue implements Stringable
{
    /**
     * @param  array<string, mixed>  $options  Optionen des Felds (Stellen, Auswahlwerte)
     */
    public function __construct(
        public mixed $raw,
        public FieldType $type,
        public array $options,
        public string $locale,
        public ?string $display = null,
    ) {}

    public function __toString(): string
    {
        if ($this->display !== null) {
            return $this->display;
        }

        $locale = self::numberLocale($this->locale);

        return match ($this->type) {
            FieldType::Boolean => __('common.yes'),
            FieldType::Integer => ($this->options[CustomField::GROUP_DIGITS] ?? false) ? (string) Number::format((int) $this->raw, locale: $locale) : (string) (int) $this->raw,
            FieldType::Decimal => (string) Number::format((float) $this->raw, (int) ($this->options['scale'] ?? 2), locale: $locale),
            FieldType::Date => Carbon::parse($this->raw)->format(self::dateFormat($this->locale, 'date')),
            FieldType::DateTime => Carbon::parse($this->raw)->format(self::dateFormat($this->locale, 'datetime')),
            FieldType::Select => Translations::pick(collect((array) ($this->options['options'] ?? []))->firstWhere('value', $this->raw)['label'] ?? null, (string) $this->raw),
            FieldType::File => implode(', ', RecordFiles::names($this->raw)),
            default => (string) $this->raw,
        };
    }

    /** Zahlen in Schweizer Schreibweise der Dokumentsprache (de_CH: 1'234.50, fr_CH: 1 234,50). */
    public static function numberLocale(string $locale): string
    {
        return $locale.'_CH';
    }

    public static function dateFormat(string $locale, string $kind): string
    {
        return (string) config("locales.available.{$locale}.{$kind}", $kind === 'date' ? 'd.m.Y' : 'd.m.Y H:i');
    }
}
