<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Schemas;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\NowDefault;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\IdentifierValidator;
use App\DynamicData\Validation\FieldRuleCheck;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\TranslatableInputs;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginRegistry;
use App\Support\Translations;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Str;

/**
 * Formular für ein Feld des Table-Designers (Startfelder beim Anlegen, Feld-Editor).
 * Fachliche Prüfung erfolgt zusätzlich im DynamicSchemaService.
 */
final class FieldForm
{
    /**
     * @return list<Component|Field>
     */
    /** „Wird vom Plugin … gebraucht“ für Felder, die ein installiertes Plugin braucht. */
    public static function pluginHint(mixed $field): ?string
    {
        // Auch im Formular einer neuen Tabelle verwendet: dort ist der Datensatz die Tabelle, nicht ein Feld.
        return $field instanceof CustomField && $field->exists
            ? app(PluginDependencies::class)->hint((string) $field->custom_table?->name, $field->name)
            : null;
    }

    public static function components(?CustomTable $owner = null): array
    {
        return [
            Grid::make(2)->schema([
                TranslatableInputs::make('label', __('common.label'), required: true, configure: static function ($input, string $locale): void {
                    if ($locale !== config('locales.default')) {
                        return;
                    }

                    $input->live(onBlur: true)->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (blank($get('name'))) {
                            $set('name', self::suggestName((string) $state));
                        }
                    });
                }),
                TextInput::make('name')
                    ->label(__('tables.fields.column_name'))
                    // Von einem Plugin gebraucht: Name und Typ fest (DynamicSchemaService prüft zusätzlich).
                    ->disabled(fn (mixed $record): bool => self::pluginHint($record) !== null)
                    ->dehydrated()
                    ->helperText(fn (mixed $record): string => self::pluginHint($record) ?? __('tables.fields.column_name_help'))
                    ->required()
                    ->maxLength(63)
                    ->rule(self::identifierRule()),
                Select::make('type')
                    ->label(__('common.type'))
                    ->options(FieldType::options())
                    ->default(FieldType::String->value)
                    ->required()
                    ->live()
                    ->disabled(fn (mixed $record): bool => self::pluginHint($record) !== null)
                    ->dehydrated()
                    ->helperText(fn (mixed $record): string => self::pluginHint($record) ?? __('tables.fields.type_help'))
                    ->columnSpanFull(),
            ]),
            self::typeOptions($owner),
            self::validation(),
            self::suggestion($owner),
            Fieldset::make(__('tables.fields.behaviour'))
                ->columns(3)
                ->schema([
                    Toggle::make('is_required')->label(__('tables.fields.required'))
                        ->visible(fn (Get $get): bool => $get('type') !== FieldType::Computed->value),
                    Toggle::make('is_unique')
                        ->label(__('tables.fields.unique'))
                        ->visible(fn (Get $get): bool => ! in_array($get('type'), [FieldType::Text->value, FieldType::Boolean->value, FieldType::Computed->value], true)),
                    Toggle::make('is_searchable')
                        ->label(__('tables.fields.searchable'))
                        ->visible(fn (Get $get): bool => in_array($get('type'), [FieldType::String->value, FieldType::Text->value, FieldType::Select->value], true)),
                    Toggle::make('is_sortable')
                        ->label(__('tables.fields.index'))
                        ->helperText(__('tables.fields.index_help'))
                        ->visible(fn (Get $get): bool => $get('type') !== FieldType::Text->value),
                    Toggle::make('show_in_table')->label(__('tables.fields.show_in_table'))->default(true),
                    Toggle::make('show_in_form')->label(__('tables.fields.show_in_form'))->default(true),
                ]),
        ];
    }

    /**
     * Formularzustand -> Eingabe für DynamicSchemaService.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toServiceData(array $data): array
    {
        $options = (array) ($data['options'] ?? []);

        if (isset($options['options'])) {
            $options['options'] = array_values((array) $options['options']);
        }

        return [
            'name' => (string) ($data['name'] ?? ''),
            'label' => Translations::clean($data['label'] ?? null),
            'type' => (string) ($data['type'] ?? ''),
            'options' => $options,
            'validation_rules' => array_filter((array) ($data['validation_rules'] ?? []), static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false && $v !== []),
            ...array_map('boolval', array_intersect_key($data, array_flip([
                'is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form',
            ]))),
        ];
    }

    /**
     * Datensatz -> Formularzustand (Bearbeiten).
     *
     * @return array<string, mixed>
     */
    public static function fillData(CustomField $field): array
    {
        return [
            ...$field->only(['name', 'is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form']),
            'label' => $field->getTranslations('label'),
            'type' => $field->type->value,
            'options' => [
                ...self::optionsForForm($field->optionsArray()),
                // Nicht gespeichert heißt „mit Tausendertrennzeichen“ (Vorgabe).
                ...($field->type === FieldType::Integer ? [CustomField::GROUP_DIGITS => $field->groupsDigits()] : []),
            ],
            'validation_rules' => (array) ($field->validation_rules ?? []),
        ];
    }

    /**
     * Auswahlwerte: Bezeichnung als Feld je Sprache, auch wenn sie als einfacher Text gespeichert ist.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function optionsForForm(array $options): array
    {
        if (isset($options['table'])) {
            $options['display_fields'] = RelationDisplay::fields($options);
            $options['separator'] = RelationDisplay::separator($options);
        }

        if (isset($options['options'])) {
            $options['options'] = array_map(
                static fn (array $option): array => [...$option, 'label' => Translations::perLocale($option['label'] ?? null)],
                array_values((array) $options['options']),
            );
        }

        return $options;
    }

    public static function suggestName(string $label): string
    {
        $name = Str::of($label)->ascii('de')->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(63, '')->toString();

        return preg_match('/^[a-z]/', $name) === 1 ? $name : ($name === '' ? '' : 'f_'.$name);
    }

    /**
     * Gleiche Prüfung wie im Service, aber als Formular-Fehler am Feld.
     * Filament wertet Closures in rule() selbst aus – daher liefert die äußere Closure
     * die eigentliche Laravel-Validierungs-Closure zurück.
     */
    public static function identifierRule(bool $table = false): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($table): void {
            try {
                $validator = app(IdentifierValidator::class);
                $table ? $validator->assertValidTableName((string) $value) : $validator->assertValidColumnName((string) $value);
            } catch (BusinessRuleException $e) {
                $fail($e->getMessage());
            }
        };
    }

    private static function typeOptions(?CustomTable $owner): Fieldset
    {
        $is = static fn (FieldType ...$types): Closure => static fn (Get $get): bool => in_array($get('type'), array_map(static fn (FieldType $t): string => $t->value, $types), true);

        // Dezimalstellen: Dezimalzahl oder berechnetes Feld mit Dezimal-Ergebnis.
        $decimal = static fn (Get $get): bool => $get('type') === FieldType::Decimal->value
            || ($get('type') === FieldType::Computed->value && $get('options.result') !== 'integer');

        return Fieldset::make(__('tables.fields.type_settings'))
            ->columns(2)
            ->visible($is(FieldType::String, FieldType::Decimal, FieldType::Select, FieldType::Relation, FieldType::Computed))
            ->schema([
                ...self::formula($owner),
                TextInput::make('options.length')
                    ->label(__('tables.fields.length'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(FieldDefinitionNormalizer::MAX_STRING_LENGTH)
                    ->default(255)
                    ->visible($is(FieldType::String)),
                TextInput::make('options.precision')
                    ->label(__('tables.fields.precision'))
                    ->integer()->minValue(1)->maxValue(38)->default(12)
                    ->visible($decimal),
                TextInput::make('options.scale')
                    ->label(__('tables.fields.scale'))
                    ->integer()->minValue(0)->maxValue(38)->default(2)
                    ->visible($decimal),
                Repeater::make('options.options')
                    ->label(__('tables.fields.options'))
                    ->schema([
                        TextInput::make('value')->label(__('tables.fields.option_value'))->required()->maxLength(FieldType::SELECT_LENGTH),
                        TranslatableInputs::make('label', __('tables.fields.option_label')),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel(__('tables.fields.option_add'))
                    ->visible($is(FieldType::Select))
                    ->columnSpanFull(),
                Select::make('options.table')
                    ->label(__('tables.fields.relation_table'))
                    ->options(fn (): array => CustomTable::labelOptions(designerOnly: true))
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('options.display_fields', []))
                    ->required($is(FieldType::Relation))
                    ->visible($is(FieldType::Relation)),
                Select::make('options.display_fields')
                    ->label(__('tables.fields.display_fields'))
                    ->helperText(__('tables.fields.display_fields_help'))
                    ->multiple()
                    // Das Trennzeichen erscheint erst ab zwei Feldern.
                    ->live()
                    ->options(fn (Get $get): array => CustomField::query()
                        ->whereHas('custom_table', fn ($q) => $q->where('name', (string) $get('options.table')))
                        ->where('type', '!=', FieldType::Relation->value)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (CustomField $field): array => [$field->name => (string) $field->label])
                        ->all())
                    ->required($is(FieldType::Relation))
                    ->visible($is(FieldType::Relation)),
                Select::make('options.separator')
                    ->label(__('tables.fields.separator'))
                    ->options(RelationDisplay::separatorOptions())
                    ->default(RelationDisplay::DEFAULT_SEPARATOR)
                    ->selectablePlaceholder(false)
                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Relation->value && count((array) $get('options.display_fields')) > 1),
                Text::make(__('tables.fields.relation_help'))
                    ->visible($is(FieldType::Relation))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Berechnetes Feld: Ergebnisart und Formel. Die Formel wird beim Verlassen geprüft (unbekannte
     * Felder, Funktionen, Kreisbezug); daneben die verfügbaren Felder und Funktionen.
     *
     * @return list<Component|Field>
     */
    private static function formula(?CustomTable $owner): array
    {
        $isComputed = static fn (Get $get): bool => $get('type') === FieldType::Computed->value;

        return [
            Select::make('options.result')
                ->label(__('tables.formula.result'))
                ->options(['decimal' => FieldType::Decimal->label(), 'integer' => FieldType::Integer->label()])
                ->default('decimal')
                ->selectablePlaceholder(false)
                ->live()
                ->visible($isComputed),
            Textarea::make('options.formula')
                ->label(__('tables.formula.label'))
                ->rows(2)
                ->required(fn (Get $get): bool => $isComputed($get))
                ->live(debounce: 600)
                // Nach jeder Änderung neu prüfen: alter Fehler weg, neuer Fehler sofort sichtbar (nicht erst beim Speichern).
                ->afterStateUpdated(static function (Textarea $component): void {
                    $component->getLivewire()->resetErrorBag($component->getStatePath());
                    $component->getLivewire()->validateOnly($component->getStatePath());
                })
                ->extraInputAttributes(['data-formula-input' => true, 'spellcheck' => 'false', 'style' => 'font-family: ui-monospace, monospace'])
                ->rule(static fn (Get $get, ?CustomField $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($owner, $get, $record): void {
                    if ($owner === null || blank($value)) {
                        return;
                    }

                    try {
                        $fields = $owner->fields()->get()->keyBy('name')->all();
                        $self = (string) ($get('name') ?: $record?->name);
                        $compiled = app(FormulaAnalyzer::class)->compile((string) $value, $fields, $self, $owner->name);
                        FormulaAnalyzer::assertNoCycle($fields, $self, $compiled['ast']);
                    } catch (BusinessRuleException $e) {
                        $fail($e->getMessage());
                    }
                })
                ->helperText(fn (Get $get, ?CustomField $record): string => self::formulaHelp($owner, (string) $get('options.formula'), (string) ($get('name') ?: $record?->name)))
                ->columnSpanFull()
                ->visible($isComputed),
            // Klickbare Felder (technische Namen), Zusammenfassungen über Kind-Tabellen, Rechenzeichen und Funktionen.
            View::make('filament.components.formula-picker')
                ->viewData(fn (Get $get, ?CustomField $record): array => self::formulaPicker($owner, $get, (string) ($get('name') ?: $record?->name)))
                ->columnSpanFull()
                ->visible($isComputed),
            Text::make(__('tables.formula.help'))
                ->columnSpanFull()
                ->visible($isComputed),
        ];
    }

    /**
     * Daten für die Formel-Bausteine: Zahlenfelder dieser Tabelle (beim Anlegen: die Felder weiter oben im Formular)
     * und je Kind-Tabelle deren Zahlenfelder als „tabelle.feld“ plus count(tabelle).
     *
     * @return array<string, mixed>
     */
    private static function formulaPicker(?CustomTable $owner, Get $get, string $self): array
    {
        $item = static fn (string $name, string $label, ?string $insert = null): array => [
            'label' => $label === '' || $label === $name ? $name : $label.' ('.$name.')',
            'insert' => $insert ?? $name,
        ];

        if ($owner !== null) {
            $own = $owner->fields()->get()
                ->filter(fn (CustomField $f): bool => $f->is_active && $f->name !== $self && in_array($f->valueType(), [FieldType::Integer, FieldType::Decimal], true))
                ->map(fn (CustomField $f): array => $item($f->name, (string) $f->label))
                ->values()
                ->all();
        } else {
            // Neue Tabelle: nur Felder, die im Formular weiter oben stehen.
            $own = [];

            foreach ((array) $get('../../fields') as $sibling) {
                $name = (string) ($sibling['name'] ?? '');

                if ($name === '' || $name === $self) {
                    break;
                }

                $type = (string) ($sibling['type'] ?? '');
                $numeric = in_array($type, [FieldType::Integer->value, FieldType::Decimal->value], true)
                    || ($type === FieldType::Computed->value);

                if ($numeric) {
                    $own[] = $item($name, Translations::pick($sibling['label'] ?? null, $name));
                }
            }
        }

        $groups = [['label' => __('tables.formula.picker_fields'), 'items' => $own]];

        foreach ($owner === null ? [] : FormulaAnalyzer::children($owner->name) as $childName => $child) {
            $fields = $child['table']->fields
                ->filter(fn (CustomField $f): bool => $f->is_active && in_array($f->valueType(), [FieldType::Integer, FieldType::Decimal], true))
                ->map(fn (CustomField $f): array => $item($childName.'.'.$f->name, (string) $f->label === '' ? '' : $f->label, $childName.'.'.$f->name))
                ->values()
                ->all();

            $groups[] = [
                'label' => __('tables.formula.picker_child', ['table' => (string) $child['table']->label, 'name' => $childName]),
                'items' => [['label' => 'count('.$childName.')', 'insert' => 'count('.$childName.')'], ...$fields],
            ];
        }

        // Formelfunktionen aus Plugins mit ihrer Beschreibung als Tooltip.
        $pluginFunctions = app(PluginRegistry::class)->formulaFunctions();

        if ($pluginFunctions !== []) {
            $groups[] = [
                'label' => __('tables.formula.picker_plugins'),
                'items' => array_values(array_map(static fn (FormulaFunction $f): array => [
                    'label' => $f->name.'()',
                    'insert' => $f->name.'(',
                    'title' => ($f->description !== '' ? $f->description : $f->name)
                        .' – '.__('plugins.origin.tooltip', ['plugin' => (string) app(PluginRegistry::class)->formulaFunctionPlugin($f->name)?->label()]),
                ], $pluginFunctions)),
            ];
        }

        return [
            'groups' => $groups,
            'buttons' => [
                ...array_map(fn (string $s): array => ['label' => $s, 'insert' => $s === ',' ? ', ' : ' '.$s.' '], ['+', '-', '*', '/', '(', ')', ',']),
                ...array_map(fn (string $f): array => ['label' => $f, 'insert' => $f.'('], ['round', 'abs', 'min', 'max', 'coalesce', 'sum', 'avg', 'count']),
            ],
            'empty' => __('tables.formula.picker_empty'),
        ];
    }

    /** Ergebnis der Prüfung unter dem Formelfeld: Fehler oder die Felder, von denen das Ergebnis abhängt. */
    private static function formulaHelp(?CustomTable $owner, string $formula, string $self): string
    {
        if ($owner === null) {
            return __('tables.formula.check_later');
        }

        if (trim($formula) === '') {
            return __('tables.formula.example');
        }

        try {
            $fields = $owner->fields()->get()->keyBy('name')->all();
            $compiled = app(FormulaAnalyzer::class)->compile($formula, $fields, $self, $owner->name);
            FormulaAnalyzer::assertNoCycle($fields, $self, $compiled['ast']);

            // Felder des Datensatzes und der zusammengefassten Kind-Tabellen, z. B. „positionen.menge“.
            $fields = [...$compiled['fields']];

            foreach (FormulaAnalyzer::aggregates($compiled['ast']) as $aggregate) {
                $fields = [...$fields, ...($aggregate['fields'] === [] ? [$aggregate['table']] : array_map(static fn (string $f): string => $aggregate['table'].'.'.$f, $aggregate['fields']))];
            }

            return __('tables.formula.depends_on', ['fields' => $fields === [] ? '—' : implode(', ', array_unique($fields))]);
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Vorgabe beim Anlegen: fortlaufend (höchster Wert + 1, nur Ganzzahl), optional je Verknüpfung,
     * oder Übernahme aus dem Master-Datensatz (Verknüpfung → Feld).
     */
    private static function suggestion(?CustomTable $owner): Fieldset
    {
        $relations = $owner === null ? [] : $owner->fields()->get()
            ->filter(fn (CustomField $f): bool => $f->type === FieldType::Relation)
            ->mapWithKeys(fn (CustomField $f): array => [$f->name => $f->label.' ('.$f->name.')'])
            ->all();

        return Fieldset::make(__('tables.fields.suggestion'))
            ->columns(2)
            ->visible(fn (Get $get): bool => FieldType::tryFrom((string) $get('type')) !== null && ! in_array($get('type'), [FieldType::Relation->value, FieldType::Computed->value], true))
            ->schema([
                Select::make('options.'.CopySource::OPTION)
                    ->label(__('tables.fields.copy_from'))
                    ->helperText($owner === null ? __('tables.fields.suggest_scope_later') : __('tables.fields.copy_from_help'))
                    ->placeholder(__('tables.fields.copy_from_none'))
                    ->options(fn (Get $get): array => $owner === null || ($type = FieldType::tryFrom((string) $get('type'))) === null
                        ? []
                        : CopySource::options($owner, $type))
                    ->disabled(fn (Get $get): bool => $owner === null || (bool) $get('options.'.SequenceSuggester::OPTION) || (bool) $get('options.'.NowDefault::OPTION))
                    ->live()
                    ->columnSpanFull(),
                Toggle::make('options.'.CustomField::GROUP_DIGITS)
                    ->label(__('tables.fields.group_digits'))
                    ->helperText(__('tables.fields.group_digits_help'))
                    ->default(true)
                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Integer->value),
                Toggle::make('options.'.SequenceSuggester::OPTION)
                    ->label(__('tables.fields.suggest_next'))
                    ->helperText(__('tables.fields.suggest_next_help'))
                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Integer->value)
                    ->disabled(fn (Get $get): bool => filled($get('options.'.CopySource::OPTION)))
                    ->live(),
                Toggle::make('options.'.NowDefault::OPTION)
                    ->label(fn (Get $get): string => $get('type') === FieldType::Date->value ? __('tables.fields.prefill_today') : __('tables.fields.prefill_now'))
                    ->helperText(__('tables.fields.prefill_now_help'))
                    ->visible(fn (Get $get): bool => in_array($get('type'), [FieldType::Date->value, FieldType::DateTime->value], true))
                    ->disabled(fn (Get $get): bool => filled($get('options.'.CopySource::OPTION)))
                    ->live(),
                Select::make('options.'.SequenceSuggester::SCOPE)
                    ->label(__('tables.fields.suggest_scope'))
                    ->helperText($owner === null ? __('tables.fields.suggest_scope_later') : __('tables.fields.suggest_scope_help'))
                    ->placeholder(__('tables.fields.suggest_scope_none'))
                    ->options($relations)
                    ->disabled($owner === null)
                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Integer->value && (bool) $get('options.'.SequenceSuggester::OPTION)),
            ]);
    }

    private static function validation(): Fieldset
    {
        $is = static fn (string ...$types): Closure => static fn (Get $get): bool => in_array($get('type'), $types, true);

        return Fieldset::make(__('tables.fields.validation'))
            ->columns(2)
            ->visible($is('string', 'text', 'integer', 'decimal'))
            ->schema([
                TextInput::make('validation_rules.min_length')->label(__('tables.fields.min_length'))->integer()->minValue(0)->visible($is('string', 'text')),
                TextInput::make('validation_rules.max_length')->label(__('tables.fields.max_length'))->integer()->minValue(0)->visible($is('string', 'text')),
                TextInput::make('validation_rules.min')->label(__('tables.fields.min'))->numeric()->visible($is('integer', 'decimal')),
                TextInput::make('validation_rules.max')->label(__('tables.fields.max'))->numeric()->visible($is('integer', 'decimal')),
                Toggle::make('validation_rules.email')->label(__('tables.fields.email'))->visible($is('string')),
                Toggle::make('validation_rules.url')->label(__('tables.fields.url'))->visible($is('string')),
                Select::make('validation_rules.'.FieldRuleCheck::OPTION)
                    ->label(__('tables.fields.plugin_checks'))
                    ->multiple()
                    // Puzzle-Symbol und Plugin in Liste und gewählten Etiketten (Texte in pluginCheckOptions maskiert).
                    ->allowHtml()
                    ->options(static fn (Get $get): array => self::pluginCheckOptions((string) $get('type'), (array) $get('validation_rules.'.FieldRuleCheck::OPTION)))
                    ->helperText(static fn (Get $get): ?string => self::pluginCheckHint((string) $get('type'), (array) $get('validation_rules.'.FieldRuleCheck::OPTION)))
                    ->visible(static fn (Get $get): bool => self::pluginCheckOptions((string) $get('type'), (array) $get('validation_rules.'.FieldRuleCheck::OPTION)) !== [])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Prüfregeln der Plugins für den Feldtyp und – damit sie sichtbar bleiben – gewählte, die nicht verfügbar sind.
     *
     * @param  array<mixed>  $selected
     * @return array<string, string>
     */
    private static function pluginCheckOptions(string $type, array $selected): array
    {
        $options = [];

        foreach (app(PluginRegistry::class)->fieldRules($type) as $key => $rule) {
            $plugin = app(PluginRegistry::class)->get((string) strstr($key, '.', true));
            $options[$key] = '<span class="saft-plugin-option" title="'.e(__('plugins.origin.tooltip', ['plugin' => $plugin->label()])).'">'
                .svg('heroicon-m-puzzle-piece', 'saft-plugin-origin-icon')->toHtml()
                .'<span>'.e($rule->label).'</span><span class="saft-plugin-option-source">'.e($plugin->label()).'</span></span>';
        }

        foreach (array_diff(array_map('strval', $selected), array_keys($options)) as $key) {
            $options[$key] = e(__('tables.fields.plugin_check_unavailable', ['rule' => $key]));
        }

        return $options;
    }

    /** @param  array<mixed>  $selected */
    private static function pluginCheckHint(string $type, array $selected): ?string
    {
        $rules = app(PluginRegistry::class)->fieldRules($type);
        $unavailable = array_diff(array_map('strval', $selected), array_keys($rules));

        if ($unavailable !== []) {
            return __('tables.fields.plugin_checks_missing', ['rules' => implode(', ', $unavailable)]);
        }

        // Was die gewählten Regeln verlangen (Beschreibung aus dem Plugin), sonst der allgemeine Hinweis.
        $described = array_filter(array_map(
            static fn (string $key): ?string => filled($rules[$key]->description ?? null) ? $rules[$key]->label.': '.$rules[$key]->description : null,
            array_map('strval', $selected),
        ));

        return $described === [] ? __('tables.fields.plugin_checks_help') : implode(' · ', $described);
    }
}
