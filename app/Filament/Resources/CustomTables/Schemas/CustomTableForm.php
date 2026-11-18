<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Schemas;

use App\Ai\AiConfig;
use App\Ai\AiUsage;
use App\DynamicData\Assistant\TableAssistant;
use App\DynamicData\DetailRelations;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Rules\RuleFieldTypes;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\SchemaVerifier;
use App\Filament\Forms\Components\IconPicker;
use App\Filament\Support\AppSelect;
use App\Filament\Support\MenuGroupSelect;
use App\Filament\Support\TableLabelHint;
use App\Filament\Support\TableNameRule;
use App\Filament\Support\TranslatableInputs;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\PluginDependencies;
use App\Support\AppLocale;
use App\Support\Translations;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use Throwable;

class CustomTableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // KI-Assistent: nur beim Anlegen und für Berechtigte (Zustand liegt in der Seite CreateCustomTable).
                View::make('filament.custom-tables.assistant')
                    // Eine Bedingung: visible() würde ein vorheriges visibleOn() ersetzen. Beim Bearbeiten gibt es „Mit KI anpassen“.
                    ->visible(fn (string $operation): bool => $operation === 'create' && TableAssistant::availableFor(auth()->user() instanceof User ? auth()->user() : null))
                    ->viewData(fn ($livewire): array => [
                        'history' => $livewire->assistantHistory ?? [],
                        'reply' => $livewire->assistantReply ?? null,
                        'replyKind' => $livewire->assistantReplyKind ?? null,
                        'remaining' => auth()->user() instanceof User ? AiUsage::remainingToday(auth()->user()) : 0,
                        'provider' => app(AiConfig::class)->providerLabel(),
                    ]),
                Section::make(__('tables.form.table'))
                    ->columns(2)
                    ->schema([
                        // App (B-107): beim Anlegen wählen; wechseln später über „In andere App verschieben“.
                        AppSelect::make()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old, string $operation): void {
                                if ($operation === 'create') {
                                    $set('name', AppSelect::switchPrefix($get('name'), $old, $state));
                                }
                            })
                            ->columnSpanFull(),
                        TranslatableInputs::make('label', __('tables.form.display_name'), required: true, configure: static function ($input, string $locale): void {
                            if ($locale !== config('locales.default')) {
                                return;
                            }

                            TableLabelHint::apply($input, $locale);

                            // Technischen Namen aus der Bezeichnung der Vorgabesprache vorschlagen.
                            $input->live(onBlur: true)->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create' && blank($get('name'))) {
                                    $set('name', AppSelect::prefix($get('app_id')).FieldForm::suggestName((string) $state));
                                }
                            });
                        }),
                        TableNameRule::apply(TextInput::make('name'), physical: true)
                            ->label(__('tables.form.technical_name'))
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? __('tables.form.technical_name_help')
                                : __('tables.form.technical_name_fixed', ['namespace' => app(DynamicDataNamespace::class)->name()]))
                            ->required()
                            ->maxLength(63)
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->rule(FieldForm::identifierRule(table: true)),
                        TranslatableInputs::make('description', __('common.description'), multiline: true),
                        IconPicker::make('icon')
                            ->placeholder('heroicon-o-table-cells'),
                        Toggle::make('is_active')
                            ->label(__('common.active'))
                            ->default(true)
                            ->inline(false)
                            // Von einem Plugin gebraucht: bleibt aktiv (DynamicSchemaService prüft zusätzlich).
                            ->disabled(fn (?CustomTable $record): bool => $record?->is_active && app(PluginDependencies::class)->hint($record->name) !== null)
                            ->dehydrated()
                            ->helperText(fn (?CustomTable $record): string => ($record?->exists ? app(PluginDependencies::class)->hint($record->name) : null)
                                ?? __('tables.form.inactive_help')),
                        Toggle::make('export_enabled')
                            ->label(__('tables.form.export_enabled'))
                            ->default(false)
                            ->inline(false)
                            ->helperText(__('tables.form.export_enabled_help')),
                        Toggle::make('import_enabled')
                            ->label(__('tables.form.import_enabled'))
                            ->default(false)
                            ->inline(false)
                            ->helperText(__('tables.form.import_enabled_help')),
                        MenuGroupSelect::make(app: fn (Get $get): mixed => $get('app_id'))
                            ->visibleOn('create'),
                    ]),
                Section::make(__('tables.fields.title'))
                    ->description(__('tables.form.fields_help'))
                    ->visibleOn('create')
                    ->schema([
                        Repeater::make('fields')
                            ->hiddenLabel()
                            ->schema(FieldForm::components())
                            ->itemLabel(fn (array $state): ?string => self::fieldSummary($state))
                            ->collapsible()
                            // Papierkorb liegt neben „Einklappen“: Löschen nur nach Rückfrage.
                            ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation()->modalHeading(__('tables.fields.delete_confirm')))
                            ->defaultItems(1)
                            ->addActionLabel(__('tables.fields.add'))
                            ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                                $names = array_map(static fn (array $f): string => (string) ($f['name'] ?? ''), array_values((array) $value));

                                if (count($names) !== count(array_unique($names))) {
                                    $fail(__('tables.form.duplicate_column'));
                                }
                            }),
                    ]),
                self::rulesSection(),
                Section::make(__('tables.form.details'))
                    ->description(__('tables.form.details_help'))
                    ->visibleOn('edit')
                    ->schema([
                        CheckboxList::make('detail_field_ids')
                            ->hiddenLabel()
                            ->options(fn (?CustomTable $record): array => $record === null ? [] : DetailRelations::options($record->name))
                            ->afterStateHydrated(fn (CheckboxList $component, ?CustomTable $record) => $component->state(array_map('strval', (array) ($record?->detail_field_ids ?? []))))
                            ->columns(2),
                        Text::make(__('tables.form.no_details'))
                            ->visible(fn (?CustomTable $record): bool => $record !== null && DetailRelations::options($record->name) === []),
                    ]),
                Section::make(__('tables.form.physical'))
                    ->visibleOn('edit')
                    ->schema([
                        Callout::make(fn (?CustomTable $record): string => $record === null ? '' : self::physicalSummary($record))
                            ->description(fn (?CustomTable $record): ?string => $record === null ? null : self::driftSummary($record))
                            ->color(fn (?CustomTable $record): string => $record !== null && self::driftSummary($record) !== null ? 'danger' : 'gray'),
                    ]),
            ]);
    }

    /** Kopfzeile eines Felds in der Liste beim Anlegen: „Eintrittsdatum · Datum · Pflicht, sortierbar“. */
    public static function fieldSummary(array $state): ?string
    {
        $label = Translations::pick($state['label'] ?? null);

        if ($label === '') {
            return null;
        }

        $type = FieldType::tryFrom((string) ($state['type'] ?? ''));
        $flags = array_keys(array_filter([
            'required' => $state['is_required'] ?? false,
            'unique' => $state['is_unique'] ?? false,
            'searchable' => $state['is_searchable'] ?? false,
            'sortable' => $state['is_sortable'] ?? false,
        ]));

        return implode(' · ', array_filter([
            $label,
            $type?->label(),
            implode(', ', array_map(fn (string $flag): string => __('tables_assistant.flags.'.$flag), $flags)),
        ]));
    }

    /**
     * Eigene Regeln auf Tabellenebene, z. B. „Lieferdatum ≥ Bestelldatum“ oder „Menge > 0“.
     * Nur beim Bearbeiten: Die Felder müssen gespeichert sein.
     */
    private static function rulesSection(): Section
    {
        $group = static fn (?CustomTable $record, ?string $field): ?string => RuleFieldTypes::group($record?->fields->firstWhere('name', $field)?->type);

        return Section::make(__('tables.rules.title'))
            ->description(__('tables.rules.help'))
            ->visibleOn('edit')
            ->collapsible()
            ->schema([
                Repeater::make('validation_rules')
                    ->hiddenLabel()
                    ->defaultItems(0)
                    ->collapsible()
                    ->addActionLabel(__('tables.rules.add'))
                    ->itemLabel(fn (array $state, ?CustomTable $record): ?string => self::ruleLabel($state, $record))
                    ->columns(3)
                    ->schema([
                        Select::make('left')
                            ->label(__('tables.rules.left'))
                            ->options(fn (?CustomTable $record): array => $record === null ? [] : RuleFieldTypes::fieldOptions($record))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('operator', null)),
                        Select::make('operator')
                            ->label(__('tables.rules.operator'))
                            ->options(fn (Get $get, ?CustomTable $record): array => collect(RuleFieldTypes::operators($group($record, $get('left'))))
                                ->mapWithKeys(fn (string $op): array => [$op => __("tables.rules.operators.{$op}")])->all())
                            ->required(),
                        ToggleButtons::make('right_kind')
                            ->label(__('tables.rules.compare_with'))
                            ->options([TableRuleNormalizer::KIND_FIELD => __('tables.rules.kinds.field'), TableRuleNormalizer::KIND_VALUE => __('tables.rules.kinds.value')])
                            ->default(TableRuleNormalizer::KIND_FIELD)
                            ->inline()
                            ->live(),
                        Select::make('right_field')
                            ->label(__('tables.rules.right_field'))
                            ->options(fn (Get $get, ?CustomTable $record): array => $record === null ? [] : RuleFieldTypes::fieldOptions($record, $group($record, $get('left')), $get('left')))
                            ->visible(fn (Get $get): bool => $get('right_kind') !== TableRuleNormalizer::KIND_VALUE)
                            ->required(fn (Get $get): bool => $get('right_kind') !== TableRuleNormalizer::KIND_VALUE),
                        TextInput::make('right_value')
                            ->label(__('tables.rules.right_value'))
                            ->helperText(fn (Get $get, ?CustomTable $record): ?string => match ($group($record, $get('left'))) {
                                RuleFieldTypes::BOOLEAN => __('tables.rules.boolean_help'),
                                default => null,
                            })
                            ->maxLength(255)
                            ->visible(fn (Get $get, ?CustomTable $record): bool => $get('right_kind') === TableRuleNormalizer::KIND_VALUE && $group($record, $get('left')) !== RuleFieldTypes::DATE)
                            ->required(fn (Get $get, ?CustomTable $record): bool => $get('right_kind') === TableRuleNormalizer::KIND_VALUE && $group($record, $get('left')) !== RuleFieldTypes::DATE),
                        DatePicker::make('right_date')
                            ->label(__('tables.rules.right_value'))
                            ->visible(fn (Get $get, ?CustomTable $record): bool => $get('right_kind') === TableRuleNormalizer::KIND_VALUE && $group($record, $get('left')) === RuleFieldTypes::DATE)
                            ->required(fn (Get $get, ?CustomTable $record): bool => $get('right_kind') === TableRuleNormalizer::KIND_VALUE && $group($record, $get('left')) === RuleFieldTypes::DATE),
                        Toggle::make('when_filled')
                            ->label(__('tables.rules.when_filled'))
                            ->helperText(__('tables.rules.when_filled_help'))
                            ->default(true)
                            ->inline(false)
                            ->columnSpanFull(),
                        TranslatableInputs::make('message', __('tables.rules.message'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function ruleLabel(array $state, ?CustomTable $record): ?string
    {
        if (blank($state['left'] ?? null) || blank($state['operator'] ?? null)) {
            return null;
        }

        $label = static fn (?string $name): string => (string) ($record?->fields->firstWhere('name', $name)?->label ?? $name);
        $right = ($state['right_kind'] ?? null) === TableRuleNormalizer::KIND_VALUE
            ? (string) ($state['right_value'] ?? $state['right_date'] ?? '')
            : $label($state['right_field'] ?? null);

        return $label($state['left']).' '.(['=' => '=', '<>' => '≠', '<' => '<', '<=' => '≤', '>' => '>', '>=' => '≥'][$state['operator']] ?? '').' '.$right;
    }

    private static function physicalSummary(CustomTable $table): string
    {
        $qualified = app(DynamicDataNamespace::class)->qualify($table->name);

        try {
            $count = DynamicRecord::forTable($table)->newQuery()->withTrashed()->count();

            return __('tables.form.physical_count', ['table' => $qualified, 'count' => Number::format($count, locale: AppLocale::numberLocale())]);
        } catch (Throwable) {
            return __('tables.form.physical_unreadable', ['table' => $qualified]);
        }
    }

    private static function driftSummary(CustomTable $table): ?string
    {
        $issues = array_values(array_filter(
            app(SchemaVerifier::class)->issues(),
            // Meldungen nennen die Tabelle in Anführungszeichen der jeweiligen Sprache.
            static fn (string $issue): bool => preg_match('/[„“«]\s?'.preg_quote($table->name, '/').'\s?[“”»]/u', $issue) === 1,
        ));

        return $issues === [] ? null : __('tables.form.drift', ['issues' => implode(' ', $issues)]);
    }
}
