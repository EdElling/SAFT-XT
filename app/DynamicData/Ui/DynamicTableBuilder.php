<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\SystemFields;
use App\DynamicData\UserNames;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Support\Translations;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\ArgumentValue;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\HtmlString;

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

        return [
            ...array_map(function (CustomField $field) use ($shown): Column {
                $column = $this->column($field);

                return $field->is_required ? $column : $column->toggleable(isToggledHiddenByDefault: ! in_array($field->name, $shown, true));
            }, $this->availableFields($table, $except)),
            // B-159: angelegt/geändert am und von, ausgeblendet.
            ...array_map(fn (CustomField $field): Column => $this->systemColumn($field)->toggleable(isToggledHiddenByDefault: true), SystemFields::for($table)),
        ];
    }

    /** Spalte einer Systemspalte: Datum wie ein Feld (sortierbar), Person mit Namen (nicht sortierbar, andere Datenbank). */
    private function systemColumn(CustomField $field): Column
    {
        if (! SystemFields::isPerson($field)) {
            return $this->column($field);
        }

        return TextColumn::make($field->name)
            ->label($field->label)
            ->formatStateUsing(static fn (mixed $state): ?string => app(UserNames::class)->name($state))
            ->placeholder('—');
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

            // Pflichtfelder einer Designer-Tabelle sind nie leer: dort ohne „leer/gefüllt“. Angebundene Tabellen halten
            // sich nicht an SAFTs Pflicht.
            $empty = $table->isExternal() || ! $field->is_required;

            // Werteliste aus einer CHECK-Regel der Datenbank: Auswahl statt Textsuche.
            // B-158: Auswahllisten mit den Einträgen „(leer)“ und „(gefüllt)“, Eingaben mit der Auswahl „Inhalt“.
            $filter = is_array($values) ? $this->entriesFilter($field, array_combine(array_map('strval', $values), array_map('strval', $values)), $empty)->multiple()
                : match ($field->valueType()) {
                    // Ja, Nein oder leer (NULL): „Nein“ bleibt = false wie is_false der Auswertungen.
                    FieldType::Boolean => SelectFilter::make($field->name)
                        ->options(['1' => __('common.yes'), '0' => __('common.no'), ...($empty ? [EmptyFilter::EMPTY => __('data.filters.empty_entry')] : [])])
                        ->query(fn (Builder $query, array $data): Builder => EmptyFilter::entries(
                            $query,
                            $field,
                            [$data['value'] ?? null],
                            static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                        )),
                    // Dateien: mit oder ohne (leere Felder speichert SAFT als NULL).
                    FieldType::File => TernaryFilter::make($field->name)->nullable()
                        ->trueLabel(__('data.filters.with_files'))
                        ->falseLabel(__('data.filters.without_files')),
                    FieldType::Select => $this->entriesFilter($field, collect((array) ($options['options'] ?? []))->mapWithKeys(fn (array $o): array => [$o['value'] => Translations::pick($o['label'] ?? null, (string) $o['value'])])->all(), $empty)
                        ->multiple(),
                    FieldType::Relation => $this->entriesFilter($field, fn (): array => $this->relationOptions($options), $empty)
                        ->searchable(),
                    FieldType::String, FieldType::Text => Filter::make($field->name)
                        // Eine Zeile je Feld (Bezeichnung links, Eingaben rechts verbunden): der Dialog bleibt kurz.
                        ->schema([FusedGroup::make([
                            TextInput::make('value')->label(__('data.filters.contains', ['field' => $field->label]))->placeholder(__('data.filters.contains_short')),
                            ...($empty ? [EmptyFilter::select($field)] : []),
                        ])->label($field->label)->columns($empty ? 2 : 1)])
                        ->query(fn (Builder $query, array $data): Builder => EmptyFilter::content($query, $field, $data)->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q): Builder => $q->whereLike($field->name, '%'.$data['value'].'%'),
                        ))
                        ->indicateUsing(fn (array $data): array => array_values(array_filter([
                            filled($data['value'] ?? null) ? Indicator::make($field->label.': '.$data['value'])->removeField('value') : null,
                            EmptyFilter::indicator($field, $data),
                        ]))),
                    FieldType::Integer, FieldType::Decimal => Filter::make($field->name)
                        ->schema([FusedGroup::make([
                            TextInput::make('from')->label(__('data.filters.from', ['field' => $field->label]))->numeric()->prefix(__('data.filters.from_short')),
                            TextInput::make('until')->label(__('data.filters.until', ['field' => $field->label]))->numeric()->prefix(__('data.filters.until_short')),
                            ...($empty ? [EmptyFilter::select($field)->columnSpanFull()] : []),
                        ])->label($field->label)->columns(2)])
                        ->query(fn (Builder $query, array $data): Builder => EmptyFilter::content($query, $field, $data)
                            ->when(is_numeric($data['from'] ?? null), fn (Builder $q): Builder => $q->where($field->name, '>=', $field->valueType() === FieldType::Integer ? $this->integerBound($data['from'], lower: true) : $data['from']))
                            ->when(is_numeric($data['until'] ?? null), fn (Builder $q): Builder => $q->where($field->name, '<=', $field->valueType() === FieldType::Integer ? $this->integerBound($data['until'], lower: false) : $data['until'])))
                        ->indicateUsing(fn (array $data): array => array_values(array_filter([EmptyFilter::indicator($field, $data)]))),
                    FieldType::Date, FieldType::DateTime => Filter::make($field->name)
                        ->schema([FusedGroup::make([
                            DatePicker::make('from')->label(__('data.filters.from', ['field' => $field->label]))->prefix(__('data.filters.from_short')),
                            DatePicker::make('until')->label(__('data.filters.until', ['field' => $field->label]))->prefix(__('data.filters.until_short')),
                            ...($empty ? [EmptyFilter::select($field)->columnSpanFull()] : []),
                        ])->label($field->label)->columns(2)])
                        ->query(fn (Builder $query, array $data): Builder => EmptyFilter::content($query, $field, $data)
                            ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate($field->name, '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate($field->name, '<=', $d)))
                        ->indicateUsing(fn (array $data): array => array_values(array_filter([EmptyFilter::indicator($field, $data)]))),
                    // valueType(): berechnete Felder sind Zahlen, Nummernkreise Text – hier nie selbst.
                    FieldType::Computed, FieldType::Number => null,
                };

            if ($filter !== null) {
                $filters[] = $filter->label($field->label);
            }
        }

        // B-159: Zeitraum für angelegt/geändert am, Person für angelegt/geändert von (B-158: auch ohne Person).
        foreach (SystemFields::for($table) as $field) {
            $filters[] = (SystemFields::isPerson($field)
                ? $this->entriesFilter($field, static fn (): array => app(UserNames::class)->all())->searchable()
                : Filter::make($field->name)
                    ->schema([FusedGroup::make([
                        DatePicker::make('from')->label(__('data.filters.from', ['field' => $field->label]))->prefix(__('data.filters.from_short')),
                        DatePicker::make('until')->label(__('data.filters.until', ['field' => $field->label]))->prefix(__('data.filters.until_short')),
                    ])->label($field->label)->columns(2)])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate($q->getModel()->qualifyColumn($field->name), '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate($q->getModel()->qualifyColumn($field->name), '<=', $d)))
            )->label($field->label);
        }

        return $filters;
    }

    /**
     * Anordnung des Filterdialogs: zwei Spalten, Bezeichnung und Eingabe in einer Zeile (oben ausgerichtet); die vier
     * Systemspalten (angelegt/geändert am und von) am Ende in einem eingeklappten Abschnitt, angeordnet wie die Felder.
     *
     * @param  array<string, Component>  $filters  Filter je Name, wie Filament sie für das Formular gruppiert
     * @return list<Component>
     */
    public static function filtersFormSchema(array $filters): array
    {
        $system = array_intersect_key($filters, array_flip([...SystemFields::DATES, ...SystemFields::PERSONS]));
        $inline = static fn (Component $filter): Component => $filter->inlineLabel();
        $fields = array_map($inline, array_values(array_diff_key($filters, $system)));

        return $system === [] ? $fields : [
            ...$fields,
            Section::make(__('data.filters.system_section'))
                ->schema(array_map($inline, array_values($system)))
                ->columns(2)
                ->columnSpanFull()
                ->compact()
                ->collapsible()
                ->collapsed(),
        ];
    }

    /**
     * Auswahlliste (einfach oder mehrfach) mit den Einträgen „(leer)“ und „(gefüllt)“ zuoberst (B-158).
     *
     * @param  array<int|string, string>|Closure(): array<int|string, string>  $options
     */
    private function entriesFilter(CustomField $field, array|Closure $options, bool $empty = true): SelectFilter
    {
        return SelectFilter::make($field->name)
            ->options(static fn (): array => $empty
                ? EmptyFilter::withEntries($options instanceof Closure ? $options() : $options)
                : ($options instanceof Closure ? $options() : $options))
            ->query(static fn (Builder $query, array $data): Builder => EmptyFilter::entries(
                $query,
                $field,
                array_key_exists('values', $data) ? (array) $data['values'] : [$data['value'] ?? null],
            ));
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
            FieldType::File => TextColumn::make($field->name)
                // HtmlString statt html(): Filaments Bereinigung entfernte onclick, data-Attribute und den Knopf „+N“.
                // fileSummary() maskiert alle Werte selbst.
                ->state(fn (DynamicRecord $record): ?HtmlString => ($html = self::fileSummary($record->getAttribute($field->name))) === null ? null : new HtmlString($html)),
            default => TextColumn::make($field->name),
        };

        $column->label($field->label);

        if ($column instanceof TextColumn) {
            $column->placeholder('—');
        }

        if ($field->is_searchable) {
            $column->searchable();
        }

        // Alle Spalten sortierbar (ausser Dateien); "Index anlegen" im Designer beschleunigt große Tabellen.
        if ($field->type !== FieldType::File) {
            $column->sortable($field->type === FieldType::Relation ? [$field->name.self::RELATION_SUFFIX] : true);
        }

        return $column;
    }

    /** Bilder an der Endung erkennen (die Liste fragt record_files nicht je Zeile ab). */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Dateien in der Liste: bis zu drei Vorschaubilder bzw. Symbol mit Namen, dazu „+N“; in den Details alle. Namen
     * werden maskiert. data-saft-files enthält alle Dateien des Felds für die Vorschau im Overlay (Bilder, PDF);
     * ohne JavaScript öffnen die Links die Datei wie bisher.
     */
    public static function fileSummary(mixed $value, ?int $limit = 3): ?string
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $items = array_values(array_filter(is_array($value) ? $value : [], static fn (mixed $i): bool => is_array($i) && is_string($i['id'] ?? null)));

        if ($items === []) {
            return null;
        }

        $files = array_map(static fn (array $item): array => [
            'name' => (string) ($item['name'] ?? ''),
            'url' => route('files.show', ['file' => $item['id']]),
            'preview' => self::previewKind((string) ($item['name'] ?? '')),
        ], $items);

        $parts = [];

        foreach (array_slice($files, 0, $limit) as $index => $file) {
            $attributes = 'href="'.e($file['url']).'" target="_blank" rel="noopener" data-saft-file="'.$index.'"'
                .' onclick="event.stopPropagation(); if (window.saftFilePreview && window.saftFilePreview(this)) event.preventDefault();"';

            if ($file['preview'] === 'image') {
                $parts[] = '<a '.$attributes.' title="'.e($file['name']).'">'
                    .'<img src="'.e($file['url'].'?thumb=1').'" alt="'.e($file['name']).'" class="saft-file-thumb" loading="lazy"></a>';

                continue;
            }

            $parts[] = '<a '.$attributes.' class="saft-file-link">'
                .svg('heroicon-m-paper-clip', 'saft-file-icon')->toHtml()
                .'<span>'.e($limit !== null && count($files) > 1 ? mb_strimwidth($file['name'], 0, 24, '…') : $file['name']).'</span></a>';
        }

        $more = $limit !== null && count($files) > $limit
            // Ein Link, kein Knopf: Die Zelle liegt in Filaments eigenem <button>, und ein Knopf darin würde vom Browser
            // aus der Liste geschoben (ohne Zugriff auf data-saft-files).
            ? '<a href="'.e($files[$limit]['url']).'" target="_blank" rel="noopener" role="button" class="saft-file-more" data-saft-file="'.$limit.'" title="'.e(__('data.files.show_all')).'"'
                .' onclick="event.stopPropagation(); if (window.saftFilePreview && window.saftFilePreview(this, true)) event.preventDefault();">+'.(count($files) - $limit).'</a>'
            : '';

        return '<span class="saft-file-list" data-saft-files="'.e((string) json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'">'
            .implode('', $parts).$more.'</span>';
    }

    /** Vorschau im Overlay: image, pdf oder null (wird heruntergeladen). */
    private static function previewKind(string $name): ?string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, self::IMAGE_EXTENSIONS, true) => 'image',
            $extension === 'pdf' => 'pdf',
            default => null,
        };
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
