<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\App;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Anzeige einer Verknüpfung: ein oder mehrere Felder des verknüpften Datensatzes, verbunden mit einem
 * Trennzeichen (z. B. „Anna Keller“ oder „ETH Zürich, CH“). Ältere Verknüpfungen speichern nur
 * display_field; sie gelten als Liste mit einem Feld.
 */
final class RelationDisplay
{
    /** Wählbare Trennzeichen; Schlüssel = gespeicherter Wert. */
    public const array SEPARATORS = [' ', ', ', ' – ', ' · ', ' / '];

    public const string DEFAULT_SEPARATOR = ' ';

    /**
     * @param  array<string, mixed>  $options  Optionen eines Verknüpfungsfelds
     * @return list<string>
     */
    public static function fields(array $options): array
    {
        $fields = array_values(array_filter(
            array_map('strval', (array) ($options['display_fields'] ?? [])),
            static fn (string $field): bool => $field !== '',
        ));

        if ($fields === [] && filled($options['display_field'] ?? null)) {
            $fields = [(string) $options['display_field']];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function separator(array $options): string
    {
        $separator = (string) ($options['separator'] ?? self::DEFAULT_SEPARATOR);

        return in_array($separator, self::SEPARATORS, true) ? $separator : self::DEFAULT_SEPARATOR;
    }

    /**
     * Optionen mit Feldliste und Trennzeichen; display_field bleibt als erstes Feld erhalten
     * (Sortierung, ältere Auswertungen).
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function store(array $options, array $fields, ?string $separator): array
    {
        $fields = array_values(array_unique(array_filter(array_map('strval', $fields), static fn (string $f): bool => $f !== '')));
        $separator = in_array($separator, self::SEPARATORS, true) ? $separator : self::DEFAULT_SEPARATOR;

        return [...$options, 'display_field' => $fields[0] ?? null, 'display_fields' => $fields, 'separator' => $separator];
    }

    /**
     * Werte verbinden; leere Werte entfallen (kein hängendes Trennzeichen).
     *
     * @param  iterable<mixed>  $values
     */
    public static function compose(iterable $values, string $separator): ?string
    {
        $parts = [];

        foreach ($values as $value) {
            $text = trim((string) $value);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode($separator, $parts);
    }

    /**
     * Auswahlliste Schlüssel => Anzeige, sortiert nach dem ersten Anzeigefeld.
     *
     * @param  Builder<DynamicRecord>  $query  Abfrage der Zieltabelle (ggf. mit Suche)
     * @param  array<string, mixed>  $options
     * @return array<int|string, string>
     */
    public static function options(Builder $query, array $options, int $limit): array
    {
        if (self::unavailable($options)) {
            return [];
        }

        $model = $query->getModel();
        $fields = self::fields($options);
        $separator = self::separator($options);

        return $query
            ->orderBy($fields[0] ?? $model->getKeyName())
            ->limit($limit)
            ->get([$model->getKeyName(), ...$fields])
            ->mapWithKeys(fn (Model $record): array => [
                $record->getKey() => self::compose(array_map(static fn (string $f): mixed => $record->getAttribute($f), $fields), $separator) ?? '#'.$record->getKey(),
            ])
            ->all();
    }

    /**
     * Zieltabelle in einer deaktivierten App (B-107, Schnittstellen): Verweise zeigen „nicht verfügbar“, die Auswahl
     * bleibt leer – ausser für den Super-Admin. Einmal je Anfrage und Tabelle ermittelt.
     *
     * @param  array<string, mixed>  $options
     */
    public static function unavailable(array $options): bool
    {
        $table = (string) ($options['table'] ?? '');
        $key = 'saft.relation.app.'.$table;
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, CustomTable::query()->where('name', $table)->value('app_id'));
        }

        $appId = $attributes->get($key);
        $user = auth()->user();

        return $appId !== null && ! App::isActiveId((int) $appId) && ! ($user instanceof User && $user->isSuperAdmin());
    }

    /**
     * Anzeige eines einzelnen Datensatzes der Zieltabelle.
     *
     * @param  array<string, mixed>  $options
     */
    public static function label(?Model $record, array $options): ?string
    {
        if (self::unavailable($options)) {
            return __('apps.interfaces.unavailable');
        }

        if ($record === null) {
            return null;
        }

        return self::compose(array_map(static fn (string $f): mixed => $record->getAttribute($f), self::fields($options)), self::separator($options));
    }

    /**
     * Suche über alle Anzeigefelder (z. B. Vor- oder Nachname).
     *
     * @param  Builder<DynamicRecord>  $query
     * @param  array<string, mixed>  $options
     * @return Builder<DynamicRecord>
     */
    public static function search(Builder $query, array $options, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($options, $search): void {
            foreach (self::fields($options) as $field) {
                $q->orWhereLike($field, "%{$search}%");
            }
        });
    }

    /** Spaltenname der Unterabfrage für das n-te Anzeigefeld (das erste ohne Nummer). */
    public static function alias(string $field, int $index): string
    {
        return $field.'__label'.($index === 0 ? '' : '_'.$index);
    }

    /** Lesbares Trennzeichen für Auswahllisten (Leerzeichen sichtbar machen). */
    public static function separatorOptions(): array
    {
        $options = [];

        foreach (self::SEPARATORS as $separator) {
            $options[$separator] = $separator === ' ' ? __('tables.fields.separator_space') : '„'.$separator.'“';
        }

        return $options;
    }

    /** Beschreibung wie „→ personen.vorname + nachname“. */
    public static function describe(array $options): string
    {
        return '→ '.($options['table'] ?? '?').'.'.implode(' + ', self::fields($options) ?: ['?']);
    }
}
