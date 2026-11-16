<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Zeilen ohne einzelnen Schlüssel (zusammengesetzter Schlüssel, View, Tabelle ohne Primärschlüssel).
 *
 * Filament braucht je Zeile eine Kennung und sucht die Zeile über sie wieder (z. B. beim Klick auf
 * einen verknüpften Wert). Die Kennung trägt deshalb die Werte der Schlüsselspalten bzw. – ohne
 * Schlüssel – aller vergleichbaren Spalten und lässt sich in eine Bedingung zurückverwandeln.
 */
final class KeylessRow
{
    private const string PREFIX = 'row-';

    public static function key(CustomTable $table, Model $record): string
    {
        $values = [];

        foreach (self::columns($table) as $column) {
            $values[$column] = $record->getAttributes()[$column] ?? null;
        }

        return self::encode($values);
    }

    /**
     * Kennung aus Spaltenwerten (auch für das Protokoll bei zusammengesetzten Schlüsseln).
     *
     * @param  array<string, mixed>  $values
     */
    public static function encode(array $values): string
    {
        // Als Text: Formular ("3") und Datenbank (3) ergeben dieselbe Kennung.
        $values = array_map(static fn (mixed $v): mixed => is_scalar($v) && ! is_bool($v) ? (string) $v : $v, $values);

        return self::PREFIX.rtrim(strtr(base64_encode((string) json_encode($values)), '+/', '-_'), '=');
    }

    /**
     * Werte einer Kennung; null, wenn sie keine Kennung dieser Art ist.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $key): ?array
    {
        if (! str_starts_with($key, self::PREFIX)) {
            return null;
        }

        $values = json_decode((string) base64_decode(strtr(substr($key, strlen(self::PREFIX)), '-_', '+/'), true), true);

        return is_array($values) ? $values : null;
    }

    /** Lesbar für Protokoll und Widgets, z. B. „(f5c0b035-…, 16)“; andere Schlüssel unverändert. */
    public static function describe(string $key): string
    {
        $values = self::decode($key);

        return $values === null ? $key : '('.implode(', ', array_map(static fn (mixed $v): string => $v === null ? '—' : (string) (is_scalar($v) ? $v : json_encode($v)), $values)).')';
    }

    /**
     * Schränkt die Abfrage auf die Zeile der Kennung ein; null bei unbrauchbarer Kennung.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>|null
     */
    public static function constrain(CustomTable $table, Builder $query, string $key): ?Builder
    {
        if (! str_starts_with($key, self::PREFIX)) {
            return null;
        }

        $values = self::decode($key);
        $columns = self::columns($table);

        if (! is_array($values) || array_keys($values) !== $columns) {
            return null;
        }

        foreach ($values as $column => $value) {
            $qualified = $query->getModel()->qualifyColumn($column);
            $value === null ? $query->whereNull($qualified) : $query->where($qualified, $value);
        }

        return $query;
    }

    /**
     * Zusammengesetzter Schlüssel: seine Spalten. Sonst alle sichtbaren Spalten außer langen
     * Textanzeigen (json, xml), die sich nicht zuverlässig vergleichen lassen.
     *
     * @return list<string>
     */
    private static function columns(CustomTable $table): array
    {
        $keys = array_values((array) ($table->key_columns ?? []));

        if (count($keys) > 1) {
            return $keys;
        }

        return $table->fields
            ->filter(fn (CustomField $f): bool => $f->is_active && ! ($f->type === FieldType::Text && ($f->optionsArray()['text_only'] ?? false)))
            ->pluck('name')
            ->values()
            ->all();
    }
}
