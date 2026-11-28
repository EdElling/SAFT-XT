<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\SystemFields;
use App\Models\CustomField;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

/**
 * „Leer“ bzw. „gefüllt“ in den Filtern der Datenansicht (B-158), mit derselben Bedeutung wie is_empty/not_empty der
 * Auswertungen (ReportQuery): Text, Nummernkreis und Auswahl sind bei NULL oder Leertext leer, alles andere bei NULL.
 * Filter mit Eingabe bekommen die Auswahl „Inhalt“ (content), Filter mit Auswahlliste die Einträge EMPTY und FILLED.
 */
final class EmptyFilter
{
    public const string EMPTY = '__empty';

    public const string FILLED = '__filled';

    /** Zustandsschlüssel der Auswahl „Inhalt“ in Filtern mit Eingabe. */
    public const string CONTENT = 'content';

    /**
     * Einträge „(leer)“ und „(gefüllt)“ zuoberst in einer Auswahlliste.
     *
     * @param  array<int|string, string>  $options
     * @return array<int|string, string>
     */
    public static function withEntries(array $options, bool $filled = true): array
    {
        // + statt array_merge: Schlüssel von Verknüpfungen und Personen sind Zahlen.
        return [self::EMPTY => __('data.filters.empty_entry'), ...($filled ? [self::FILLED => __('data.filters.filled_entry')] : [])] + $options;
    }

    /** Auswahl „Inhalt“: alle (Vorgabe), nur leere, nur gefüllte. */
    public static function select(CustomField $field): Select
    {
        return Select::make(self::CONTENT)
            ->label(__('data.filters.content', ['field' => $field->label]))
            ->options([self::EMPTY => __('data.filters.only_empty'), self::FILLED => __('data.filters.only_filled')])
            ->placeholder(__('data.filters.any'))
            ->selectablePlaceholder();
    }

    /**
     * Auswahl „Inhalt“ auf die Abfrage anwenden (UND mit den übrigen Eingaben des Felds).
     *
     * @param  Builder<*>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<*>
     */
    public static function content(Builder $query, CustomField $field, array $data): Builder
    {
        return match ($data[self::CONTENT] ?? null) {
            self::EMPTY => $query->where(fn (Builder $q) => self::whereEmpty($q, $field)),
            self::FILLED => $query->where(fn (Builder $q) => self::whereFilled($q, $field)),
            default => $query,
        };
    }

    /**
     * Auswahlliste mit den Einträgen EMPTY und FILLED: ODER zwischen den gewählten Einträgen, wie bei den Werten.
     *
     * @param  Builder<*>  $query
     * @param  list<mixed>  $chosen
     * @param  (callable(mixed): mixed)|null  $cast  Wert der Liste → Wert der Spalte (Ja/Nein)
     * @return Builder<*>
     */
    public static function entries(Builder $query, CustomField $field, array $chosen, ?callable $cast = null): Builder
    {
        $chosen = array_values(array_filter($chosen, static fn (mixed $v): bool => filled($v) || $v === false));

        if ($chosen === []) {
            return $query;
        }

        $values = array_values(array_filter($chosen, static fn (mixed $v): bool => ! in_array($v, [self::EMPTY, self::FILLED], true)));
        $column = $query->qualifyColumn($field->name);

        return $query->where(function (Builder $q) use ($field, $chosen, $values, $column, $cast): void {
            if ($values !== []) {
                $q->orWhereIn($column, $cast === null ? $values : array_map($cast, $values));
            }

            if (in_array(self::EMPTY, $chosen, true)) {
                $q->orWhere(fn (Builder $inner) => self::whereEmpty($inner, $field));
            }

            if (in_array(self::FILLED, $chosen, true)) {
                $q->orWhere(fn (Builder $inner) => self::whereFilled($inner, $field));
            }
        });
    }

    /** Kärtchen „Feld: leer“ bzw. „Feld: gefüllt“ zur Auswahl „Inhalt“; entfernt nur diese. */
    public static function indicator(CustomField $field, array $data): ?Indicator
    {
        $text = match ($data[self::CONTENT] ?? null) {
            self::EMPTY => __('data.filters.is_empty'),
            self::FILLED => __('data.filters.is_filled'),
            default => null,
        };

        return $text === null ? null : Indicator::make($field->label.': '.$text)->removeField(self::CONTENT);
    }

    /** Leertext zählt bei Textspalten als leer (wie Art „text“ der Auswertungen); Personen sind Benutzer-IDs. */
    public static function isText(CustomField $field): bool
    {
        return ! SystemFields::isPerson($field) && in_array($field->valueType(), [FieldType::String, FieldType::Text, FieldType::Select], true);
    }

    /** @param  Builder<*>  $query */
    private static function whereEmpty(Builder $query, CustomField $field): void
    {
        $column = $query->qualifyColumn($field->name);
        $query->whereNull($column);

        if (self::isText($field)) {
            $query->orWhere($column, '');
        }
    }

    /** @param  Builder<*>  $query */
    private static function whereFilled(Builder $query, CustomField $field): void
    {
        $column = $query->qualifyColumn($field->name);
        $query->whereNotNull($column);

        if (self::isText($field)) {
            $query->where($column, '<>', '');
        }
    }
}
