<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Support\Translations;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\ArgumentValue;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Spalten, Filter und Abfrage einer Designer-Tabelle aus den Metadaten.
 */
final class DynamicTableBuilder
{
    public const string RELATION_SUFFIX = '__label';

    /**
     * Abfrage inkl. Anzeigewerten von Verknüpfungen als Subquery (kein N+1, portabel).
     *
     * @return Builder<DynamicRecord>
     */
    /**
     * @param  list<string>|null  $visible
     * @param  list<string>  $except  z. B. Verknüpfungsfeld zur Haupttabelle in Detailtabellen
     * @param  bool  $toggleable  alle aktiven Felder als Spalten (Spaltenauswahl), nicht nur die sichtbaren
     */
    public function query(CustomTable $table, ?array $visible = null, array $except = [], bool $toggleable = false): Builder
    {
        $model = DynamicRecord::forTable($table);
        $query = $model->newQuery()->select($model->getTable().'.*');

        foreach ($toggleable ? $this->availableFields($table, $except) : $this->tableFields($table, $visible, $except) as $field) {
            if ($field->type !== FieldType::Relation) {
                continue;
            }

            $options = $field->optionsArray();

            try {
                $target = DynamicRecord::forTableName((string) $options['table']);
            } catch (ModelNotFoundException) {
                continue;
            }

            // Je Anzeigefeld eine Unterabfrage; verbunden wird in PHP (portabel, ohne SQL-Dialekt).
            foreach (RelationDisplay::fields($options) as $index => $display) {
                $query->addSelect([
                    RelationDisplay::alias($field->name, $index) => $target->newQuery()->withTrashed()
                        ->select($display)
                        ->whereColumn($target->qualifyColumn($target->getKeyName()), $model->qualifyColumn($field->name))
                        ->limit(1),
                ]);
            }
        }

        return $query;
    }

    /** Anzeigewert einer Verknüpfung aus den Unterabfragen von query(). */
    public static function relationLabel(CustomField $field, DynamicRecord $record): ?string
    {
        $options = $field->optionsArray();
        $values = [];

        foreach (RelationDisplay::fields($options) as $index => $display) {
            $values[] = $record->getAttribute(RelationDisplay::alias($field->name, $index));
        }

        return RelationDisplay::compose($values, RelationDisplay::separator($options));
    }

    /**
     * @param  list<string>|null  $visible  Spalten der Ansicht (Menüeintrag); null = Designer-Vorgabe
     * @param  list<string>  $except
     * @return list<Column>
     */
    public function columns(CustomTable $table, ?array $visible = null, array $except = [], bool $toggleable = false): array
    {
        if (! $toggleable) {
            return array_map(fn (CustomField $field): Column => $this->column($field), $this->tableFields($table, $visible, $except));
        }

        // Spaltenauswahl: alle aktiven Felder; die der Ansicht sichtbar, die übrigen eingeklappt,
        // Pflichtfelder immer sichtbar.
        $shown = array_map(static fn (CustomField $f): string => $f->name, $this->tableFields($table, $visible, $except));

        return array_map(function (CustomField $field) use ($shown): Column {
            $column = $this->column($field);

            return $field->is_required ? $column : $column->toggleable(isToggledHiddenByDefault: ! in_array($field->name, $shown, true));
        }, $this->availableFields($table, $except));
    }

    /**
     * Felder einer Spaltenauswahl in Reihenfolge der Tabelle.
     *
     * @param  list<string>  $except
     * @return list<CustomField>
     */
    public function availableFields(CustomTable $table, array $except = []): array
    {
        return $table->fields
            ->filter(fn (CustomField $f): bool => $f->is_active && ! in_array($f->name, $except, true))
            ->values()
            ->all();
    }

    /** Feld zu einem Spaltennamen (Verknüpfungen heißen <feld>__label). */
    public static function fieldName(string $column): string
    {
        return str_ends_with($column, self::RELATION_SUFFIX) ? substr($column, 0, -strlen(self::RELATION_SUFFIX)) : $column;
    }

    /**
     * @return list<BaseFilter>
     */
    /**
     * @param  list<string>  $except
     */
    public function filters(CustomTable $table, array $except = []): array
    {
        $filters = [];

        foreach ($table->fields->where('is_active', true)->whereNotIn('name', $except) as $field) {
            /** @var CustomField $field */
            $options = $field->optionsArray();
            $values = $table->isExternal() && $field->type !== FieldType::Relation ? ($options[TableConnectorService::DB_VALUES] ?? null) : null;

            // Werteliste aus einer CHECK-Regel der Datenbank: Auswahl statt Textsuche.
            $filter = is_array($values) ? SelectFilter::make($field->name)
                ->options(array_combine(array_map('strval', $values), array_map('strval', $values)))
                ->multiple()
                : match ($field->valueType()) {
                    FieldType::Boolean => TernaryFilter::make($field->name),
                    FieldType::Select => SelectFilter::make($field->name)
                        ->options(collect((array) ($options['options'] ?? []))->mapWithKeys(fn (array $o): array => [$o['value'] => Translations::pick($o['label'] ?? null, (string) $o['value'])])->all())
                        ->multiple(),
                    FieldType::Relation => SelectFilter::make($field->name)
                        ->options(fn (): array => $this->relationOptions($options))
                        ->searchable(),
                    FieldType::String, FieldType::Text => Filter::make($field->name)
                        ->schema([TextInput::make('value')->label(__('data.filters.contains', ['field' => $field->label]))])
                        ->query(fn (Builder $query, array $data): Builder => $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q): Builder => $q->whereLike($field->name, '%'.$data['value'].'%'),
                        ))
                        ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? $field->label.': '.$data['value'] : null),
                    FieldType::Integer, FieldType::Decimal => Filter::make($field->name)
                        ->schema([
                            TextInput::make('from')->label(__('data.filters.from', ['field' => $field->label]))->numeric(),
                            TextInput::make('until')->label(__('data.filters.until', ['field' => $field->label]))->numeric(),
                        ])
                        ->columns(2)
                        ->query(fn (Builder $query, array $data): Builder => $query
                            ->when(is_numeric($data['from'] ?? null), fn (Builder $q): Builder => $q->where($field->name, '>=', $field->valueType() === FieldType::Integer ? $this->integerBound($data['from'], lower: true) : $data['from']))
                            ->when(is_numeric($data['until'] ?? null), fn (Builder $q): Builder => $q->where($field->name, '<=', $field->valueType() === FieldType::Integer ? $this->integerBound($data['until'], lower: false) : $data['until']))),
                    FieldType::Date, FieldType::DateTime => Filter::make($field->name)
                        ->schema([
                            DatePicker::make('from')->label(__('data.filters.from', ['field' => $field->label])),
                            DatePicker::make('until')->label(__('data.filters.until', ['field' => $field->label])),
                        ])
                        ->columns(2)
                        ->query(fn (Builder $query, array $data): Builder => $query
                            ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate($field->name, '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate($field->name, '<=', $d))),
                };

            if ($filter !== null) {
                $filters[] = $filter->label($field->label);
            }
        }

        return $filters;
    }

    /**
     * Filtergrenze für eine Ganzzahlspalte: Die Datenbank lehnt Brüche und zu große Werte ab,
     * daher auf die nächste passende Ganzzahl runden und auf den Spaltenbereich begrenzen.
     */
    private function integerBound(int|float|string $value, bool $lower): int
    {
        $exact = filter_var($value, FILTER_VALIDATE_INT);

        if ($exact !== false) {
            return $exact;
        }

        $number = (float) $value;

        return match (true) {
            $number >= (float) PHP_INT_MAX => PHP_INT_MAX,
            $number <= (float) PHP_INT_MIN => PHP_INT_MIN,
            default => (int) ($lower ? ceil($number) : floor($number)),
        };
    }

    private function column(CustomField $field): Column
    {
        $options = $field->optionsArray();

        $column = match ($field->valueType()) {
            FieldType::Boolean => IconColumn::make($field->name)->boolean(),
            // Zahlen rechtsbündig (auch berechnete Felder, deren Ergebnis eine Zahl ist).
            FieldType::Integer, FieldType::Decimal => TextColumn::make($field->name)
                ->numeric(
                    decimalPlaces: $field->valueType() === FieldType::Decimal ? (int) $options['scale'] : 0,
                    // Jahre und Nummern ohne Tausendertrennzeichen („2024“, nicht „2.024“).
                    thousandsSeparator: $field->groupsDigits() ? ArgumentValue::Default : '',
                )
                ->alignEnd(),
            FieldType::Date => TextColumn::make($field->name)->date(),
            FieldType::DateTime => TextColumn::make($field->name)->dateTime(),
            FieldType::Select => TextColumn::make($field->name)
                ->badge()
                ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : Translations::pick(collect((array) ($options['options'] ?? []))->firstWhere('value', $state)['label'] ?? null, (string) $state)),
            // Klick auf den Anzeigewert öffnet den verknüpften Datensatz im Slide-over.
            FieldType::Relation => TextColumn::make($field->name.self::RELATION_SUFFIX)
                ->state(fn (DynamicRecord $record): ?string => self::relationLabel($field, $record))
                ->action(RelationPreview::make($field, fn (DynamicRecord $record): mixed => $record->getAttribute($field->name), 'preview_'.$field->name)),
            FieldType::Text => TextColumn::make($field->name)->limit(60)->wrap(),
            default => TextColumn::make($field->name),
        };

        $column->label($field->label);

        if ($column instanceof TextColumn) {
            $column->placeholder('—');
        }

        if ($field->is_searchable) {
            $column->searchable();
        }

        // Alle Spalten sortierbar; "Index anlegen" im Designer beschleunigt große Tabellen.
        $column->sortable($field->type === FieldType::Relation ? [$field->name.self::RELATION_SUFFIX] : true);

        return $column;
    }

    /**
     * Pflichtfelder werden immer angezeigt.
     *
     * @param  list<string>|null  $visible
     * @return list<CustomField>
     */
    public function tableFields(CustomTable $table, ?array $visible, array $except = []): array
    {
        return $table->fields
            ->filter(fn (CustomField $f): bool => $f->is_active && ! in_array($f->name, $except, true) && (
                $f->is_required || ($visible === null ? $f->show_in_table : in_array($f->name, $visible, true))
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int|string, string>
     */
    private function relationOptions(array $options): array
    {
        try {
            $target = DynamicRecord::forTableName((string) $options['table']);

            return RelationDisplay::options($target->newQuery(), $options, 200);
        } catch (ModelNotFoundException) {
            return [];
        }
    }
}
