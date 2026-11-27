<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use App\Models\DynamicRecord;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Werte im Änderungsprotokoll eines Datensatzes (attribute_changes) vergleichen und zurückschreiben (B-133 S3,
 * BULK_EDIT.md §13 Frage 14).
 *
 * Seit 0.8.1 protokolliert DynamicRecord Rohwerte, wie sie in der Datenbank stehen. Ältere Einträge haben als neuen
 * Wert eines Datums- oder Datum/Uhrzeit-Felds den Zeitpunkt in UTC („2026-08-31T22:00:00.000000Z“); raw() rechnet ihn
 * in die Zeitzone der Anwendung zurück, in der die Datenbank ihn speichert.
 */
final class LoggedValue
{
    private const array DATE_CASTS = ['date', 'datetime', 'immutable_date', 'immutable_datetime'];

    /** Wert aus dem Protokoll in der Form, die die Datenbank speichert. */
    public static function raw(DynamicRecord $model, string $field, mixed $value): mixed
    {
        if (! is_string($value) || ! $model->hasCast($field, self::DATE_CASTS) || preg_match('/T\d\d:\d\d.*(Z|[+-]\d\d:?\d\d)$/', $value) !== 1) {
            return $value;
        }

        $local = CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));

        return $model->hasCast($field, ['date', 'immutable_date']) ? $local->format('Y-m-d') : $local->format('Y-m-d H:i:s');
    }

    /** Bedeuten zwei Werte dasselbe (Rohwert, gecasteter oder protokollierter Wert, Zeitzone, Nullen nach dem Komma)? */
    public static function same(DynamicRecord $model, string $field, mixed $a, mixed $b): bool
    {
        return self::normalize($model, $field, $a) === self::normalize($model, $field, $b);
    }

    private static function normalize(DynamicRecord $model, string $field, mixed $value): ?string
    {
        if (! is_array($value) && ! $value instanceof DateTimeInterface && ! is_bool($value)) {
            $probe = $model->newInstance();
            $probe->setRawAttributes([$field => self::raw($model, $field, $value)]);
            $value = $probe->getAttribute($field);
        }

        return match (true) {
            $value === null, $value === '', $value === [] => null,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::number(sprintf('%.10F', $value)),
            is_string($value) && is_numeric($value) => self::number($value),
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /** Zahl als Text ohne Nullen am Ende (120.00 → 120), ohne Umweg über float. */
    private static function number(string $value): string
    {
        $value = ltrim(trim($value), '+');

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return in_array($value, ['-0', ''], true) ? '0' : $value;
    }
}
