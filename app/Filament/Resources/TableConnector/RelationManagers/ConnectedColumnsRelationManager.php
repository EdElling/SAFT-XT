<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\RelationManagers;

use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\TableDefinition;
use App\ExternalData\TableConnectorService;
use App\Filament\Support\TranslatableInputs;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Spalten einer angebundenen Tabelle: Typ und Herkunft kommen aus der Datenbank und sind fest;
 * Bezeichnung, Sichtbarkeit, Suche und Reihenfolge sind wählbar.
 */
class ConnectedColumnsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('table_connector.columns.title');
    }

    protected static function getModelLabel(): ?string
    {
        return __('table_connector.columns.model');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('table_connector.columns.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::allowed('view');
    }

    public function isReadOnly(): bool
    {
        return ! self::allowed('update');
    }

    private static function allowed(string $ability): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can("table_connector.{$ability}");
    }

    public function form(Schema $schema): Schema
    {
        $service = app(TableConnectorService::class);

        return $schema->components([
            TranslatableInputs::make('label', __('common.label'), required: true),
            Section::make(__('table_connector.columns.relation'))
                ->description(__('table_connector.columns.relation_help'))
                ->visible(fn (?CustomField $record): bool => $record !== null && TableConnectorService::canRelate($record))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('relation_table')
                        ->label(__('tables.fields.relation_table'))
                        ->options(fn (): array => $service->relationTargets($this->ownerTable()))
                        ->placeholder(__('table_connector.columns.relation_none'))
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) use ($service): void {
                            $target = $state === null ? null : CustomTable::query()->with('fields')->where('name', $state)->first();
                            $default = $target === null ? null : $service->defaultDisplayField($target);
                            $set('relation_display', $default === null ? [] : [$default]);
                        }),
                    Select::make('relation_display')
                        ->label(__('tables.fields.display_fields'))
                        ->helperText(__('tables.fields.display_fields_help'))
                        ->multiple()
                        ->options(fn (Get $get): array => $service->displayFieldOptions($get('relation_table')))
                        ->required(fn (Get $get): bool => filled($get('relation_table')))
                        ->live()
                        ->visible(fn (Get $get): bool => filled($get('relation_table'))),
                    Select::make('relation_separator')
                        ->label(__('tables.fields.separator'))
                        ->options(RelationDisplay::separatorOptions())
                        ->default(RelationDisplay::DEFAULT_SEPARATOR)
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get): bool => filled($get('relation_table')) && count((array) $get('relation_display')) > 1),
                ]),
            Toggle::make('show_in_table')->label(__('tables.fields.show_in_table')),
            Toggle::make('is_searchable')
                ->label(__('tables.fields.searchable'))
                ->disabled(fn (?CustomField $record): bool => ! self::searchable($record)),
            Toggle::make('is_active')->label(__('table_connector.columns.active'))->helperText(__('table_connector.columns.active_help')),
            Toggle::make('show_in_form')->label(__('table_connector.columns.in_form')),
            Toggle::make('is_read_only')
                ->label(__('table_connector.columns.read_only'))
                ->helperText(__('table_connector.columns.read_only_help'))
                ->disabled(fn (?CustomField $record): bool => $record === null || $record->is_generated || TableDefinition::isTextOnly($record)),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        $update = fn (CustomField $record, string $attribute, mixed $state): CustomField => app(TableConnectorService::class)->updateField($record, [$attribute => (bool) $state]);

        return $table
            ->recordTitleAttribute('label')
            ->reorderable('sort_order', self::allowed('update'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('label')->label(__('common.label')),
                TextColumn::make('name')->label(__('tables.fields.column'))->fontFamily('mono'),
                TextColumn::make('schema_state')
                    ->label(__('table_connector.columns.schema_state'))
                    ->badge()
                    ->state(fn (CustomField $record): ?string => self::schemaState($record))
                    ->formatStateUsing(fn (string $state): string => __('table_connector.columns.schema_'.$state))
                    ->color(fn (string $state): string => $state === 'missing' ? 'danger' : 'info'),
                TextColumn::make('type')
                    ->label(__('common.type'))
                    ->badge()
                    ->formatStateUsing(fn (FieldType $state, CustomField $record): string => ($record->optionsArray()['text_only'] ?? false) && $state !== FieldType::Relation ? __('table_connector.columns.text_only') : $state->label())
                    ->description(fn (CustomField $record): ?string => implode(' · ', array_filter([
                        $record->type === FieldType::Relation ? RelationDisplay::describe($record->optionsArray()) : null,
                        $record->external_type,
                        // Werteliste bzw. Bereich aus einer CHECK-Regel der Datenbank.
                        self::dbRule($record),
                    ])))
                    ->tooltip(fn (CustomField $record): ?string => isset($record->optionsArray()[TableConnectorService::DB_VALUES])
                        ? implode(', ', array_map('strval', (array) $record->optionsArray()[TableConnectorService::DB_VALUES]))
                        : null),
                IconColumn::make('key')
                    ->label(__('table_connector.columns.key'))
                    ->state(fn (CustomField $record): bool => in_array($record->name, (array) $this->ownerTable()->key_columns, true))
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-o-key' : null)
                    ->color('success'),
                // Eigener Name: Für das bool-Attribut würde Filament sonst Ja/Nein-Symbole erzwingen.
                IconColumn::make('generated')
                    ->label(__('table_connector.columns.generated'))
                    ->state(fn (CustomField $record): bool => $record->is_generated)
                    // Nur „ja“ markieren; ein Kreuz bei jeder normalen Spalte wäre nur Lärm.
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-o-cog-6-tooth' : null)
                    ->color('gray')
                    ->tooltip(__('table_connector.columns.generated_help')),
                ToggleColumn::make('show_in_table')
                    ->label(__('tables.fields.in_table'))
                    ->disabled(! self::allowed('update'))
                    ->updateStateUsing(fn (CustomField $record, mixed $state) => $update($record, 'show_in_table', $state)),
                ToggleColumn::make('is_searchable')
                    ->label(__('tables.fields.searchable'))
                    ->disabled(fn (CustomField $record): bool => ! self::allowed('update') || ! self::searchable($record))
                    ->updateStateUsing(fn (CustomField $record, mixed $state) => $update($record, 'is_searchable', $state)),
                ToggleColumn::make('is_active')
                    ->label(__('table_connector.columns.active'))
                    ->disabled(fn (CustomField $record): bool => ! self::allowed('update') || self::schemaState($record) === 'missing')
                    ->updateStateUsing(fn (CustomField $record, mixed $state) => $update($record, 'is_active', $state)),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (): bool => self::allowed('update'))
                    ->mutateRecordDataUsing(fn (array $data, CustomField $record): array => [...$data, 'label' => $record->getTranslations('label'), ...self::relationState($record)])
                    ->using(function (CustomField $record, array $data): CustomField {
                        $service = app(TableConnectorService::class);
                        $field = $service->updateField($record, $data);

                        if (! TableConnectorService::canRelate($field)) {
                            return $field;
                        }

                        $options = $field->optionsArray();
                        $current = $field->type === FieldType::Relation
                            ? [(string) $options['table'], RelationDisplay::fields($options), RelationDisplay::separator($options)]
                            : [null, [], RelationDisplay::DEFAULT_SEPARATOR];
                        $wanted = [
                            $data['relation_table'] ?? null,
                            array_values(array_map('strval', (array) ($data['relation_display'] ?? []))),
                            (string) ($data['relation_separator'] ?? RelationDisplay::DEFAULT_SEPARATOR),
                        ];

                        return $current === $wanted ? $field : $service->setRelation($field, $wanted[0], $wanted[1], separator: $wanted[2]);
                    }),
                Action::make('removeMissing')
                    ->label(__('table_connector.actions.remove_missing'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('table_connector.actions.remove_missing_description'))
                    ->visible(fn (CustomField $record): bool => self::schemaState($record) === 'missing')
                    ->authorize(fn (): bool => self::allowed('update'))
                    ->action(fn (CustomField $record) => app(TableConnectorService::class)->removeMissingField($record)),
            ]);
    }

    /**
     * Aktuelle Verknüpfung, sonst der Vorschlag aus dem Fremdschlüssel der Datenbank (nicht nach
     * einer von Hand entfernten Verknüpfung).
     *
     * @return array{relation_table: ?string, relation_display: list<string>, relation_separator: string}
     */
    private static function relationState(CustomField $field): array
    {
        $options = $field->optionsArray();

        if ($field->type === FieldType::Relation) {
            return ['relation_table' => (string) $options['table'], 'relation_display' => RelationDisplay::fields($options), 'relation_separator' => RelationDisplay::separator($options)];
        }

        $suggestion = ($options['relation_removed'] ?? false) ? null : app(TableConnectorService::class)->suggestRelation($field);

        return [
            'relation_table' => $suggestion['table'] ?? null,
            'relation_display' => isset($suggestion['display_field']) ? [$suggestion['display_field']] : [],
            'relation_separator' => RelationDisplay::DEFAULT_SEPARATOR,
        ];
    }

    private static function dbRule(CustomField $field): ?string
    {
        $options = $field->optionsArray();

        return match (true) {
            isset($options[TableConnectorService::DB_VALUES]) => __('table_connector.columns.db_values', ['count' => count((array) $options[TableConnectorService::DB_VALUES])]),
            isset($options[TableConnectorService::DB_RULES]) => __('table_connector.columns.db_range', ['range' => self::range((array) $options[TableConnectorService::DB_RULES])]),
            default => null,
        };
    }

    /**
     * @param  array<string, int|float>  $rules
     */
    private static function range(array $rules): string
    {
        return ($rules['min'] ?? '…').' – '.($rules['max'] ?? '…');
    }

    /** „new“ nach „Neu einlesen“ hinzugekommen, „missing“ in der Datenbank nicht mehr vorhanden. */
    private static function schemaState(CustomField $field): ?string
    {
        $options = $field->optionsArray();

        return match (true) {
            (bool) ($options[TableConnectorService::MISSING] ?? false) => 'missing',
            (bool) ($options[TableConnectorService::NEW] ?? false) => 'new',
            default => null,
        };
    }

    /** Suche nur in echten Textspalten (nicht in uuid, json & Co.). */
    private static function searchable(?CustomField $field): bool
    {
        return $field !== null
            && in_array($field->type, [FieldType::String, FieldType::Text], true)
            && ! ($field->optionsArray()['text_only'] ?? false);
    }

    private function ownerTable(): CustomTable
    {
        /** @var CustomTable $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }
}
