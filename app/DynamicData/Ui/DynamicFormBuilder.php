<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\NowDefault;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Rules\TableRuleValidator;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\TableDefinition;
use App\DynamicData\Validation\FieldRuleCheck;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Support\Translations;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Formularfelder und Validierung einer Tabelle aus den Metadaten. Designer-Tabellen: Unique/Exists
 * auf der app_data-Verbindung ohne gelöschte Datensätze. Angebundene Tabellen: nur beschreibbare
 * Spalten, Pflicht laut Datenbank, Prüfungen auf der externen Verbindung.
 */
final class DynamicFormBuilder
{
    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    /**
     * @param  list<string>  $except  vorbelegte/gesperrte Felder (Verknüpfung zur Haupttabelle)
     * @param  array<string, mixed>  $preset  feste Werte (z. B. die Bestellung in der Detailtabelle)
     * @return list<Field>
     */
    public function components(CustomTable $table, array $except = [], array $preset = []): array
    {
        $components = $table->fields
            ->filter(fn (CustomField $f): bool => ! in_array($f->name, $except, true) && (
                // Schlüssel, die beim Anlegen nötig sind, immer (beim Bearbeiten gesperrt).
                TableDefinition::isKeyInput($table, $f)
                || ($f->is_active && $f->show_in_form && (! $table->isExternal() || TableDefinition::isEditable($f)))
            ))
            ->map(fn (CustomField $f): Field => $this->field($table, $f))
            ->values()
            ->all();

        // Regeln der Tabelle (z. B. „Ende ≥ Beginn“) beim betroffenen Feld prüfen.
        app(TableRuleValidator::class)->apply($table, $components);

        if (! $table->isExternal()) {
            $this->suggestSequences($table, $components, $preset);
            $this->copyFromMaster($table, $components, $preset);
            $this->prefillNow($table, $components, $preset);
        }

        return $components;
    }

    private function field(CustomTable $table, CustomField $field): Field
    {
        $options = $field->optionsArray();
        // Angebundene Tabellen: Min./Max. aus CHECK-Regeln der Datenbank ergänzen eigene Regeln.
        $rules = [...(array) ($field->validation_rules ?? []), ...(array) ($options[TableConnectorService::DB_RULES] ?? [])];
        $values = $table->isExternal() ? ($options[TableConnectorService::DB_VALUES] ?? null) : null;

        $component = is_array($values) && $field->type !== FieldType::Relation ? Select::make($field->name)
            // Werteliste aus einer CHECK-Regel der Datenbank: Werte so, wie sie dort stehen.
            ->options(array_combine(array_map('strval', $values), array_map('strval', $values)))
            ->in(array_map('strval', $values))
            : match ($field->type) {
                // Wird beim Speichern berechnet: nur anzeigen, nie aus dem Formular übernehmen.
                FieldType::Computed => TextInput::make($field->name)
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder(__('data.computed_placeholder'))
                    ->helperText('= '.($options['formula'] ?? '')),
                FieldType::String => TextInput::make($field->name)
                    ->maxLength((int) $options['length'])
                    ->when($rules['email'] ?? false, fn (TextInput $c) => $c->email())
                    ->when($rules['url'] ?? false, fn (TextInput $c) => $c->url()),
                FieldType::Text => Textarea::make($field->name)->rows(4)->columnSpanFull(),
                FieldType::Integer => TextInput::make($field->name)->integer(),
                FieldType::Decimal => TextInput::make($field->name)
                    ->numeric()
                    ->step(10 ** -(int) $options['scale'])
                    // Verhindert Überlauf der Spalte (Stellen vor dem Komma).
                    ->maxValue(10 ** ((int) $options['precision'] - (int) $options['scale']) - 10 ** -(int) $options['scale']),
                FieldType::Boolean => Toggle::make($field->name)->inline(false),
                FieldType::Date => DatePicker::make($field->name),
                FieldType::DateTime => DateTimePicker::make($field->name),
                FieldType::Select => Select::make($field->name)
                    ->options(collect((array) ($options['options'] ?? []))->mapWithKeys(fn (array $o): array => [$o['value'] => Translations::pick($o['label'] ?? null, (string) $o['value'])])->all())
                    ->in(collect((array) ($options['options'] ?? []))->pluck('value')->all()),
                FieldType::Relation => $this->relationSelect($field),
            };

        $component->label($field->label)->required($table->isExternal() ? ($options['db_required'] ?? false) : $field->is_required);

        // Natürlicher Schlüssel einer angebundenen Tabelle: beim Anlegen einzugeben, danach fest.
        if ($table->isExternal() && in_array($field->name, $table->keyColumns(), true)) {
            $component->required()->disabled(fn (?Model $record): bool => $record?->exists ?? false);
        }

        foreach (['min_length' => 'minLength', 'max_length' => 'maxLength', 'min' => 'minValue', 'max' => 'maxValue'] as $key => $method) {
            if (isset($rules[$key]) && method_exists($component, $method)) {
                $component->{$method}($rules[$key]);
            }
        }

        // Prüfregeln aus Plugins („Weitere Prüfungen“).
        if (FieldRuleCheck::selected($field) !== []) {
            $component->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                foreach (app(FieldRuleCheck::class)->messages($field, $value) as $message) {
                    $fail($message);
                }
            });
        }

        if ($field->is_unique) {
            $component->rule(fn (?Model $record): Unique => Rule::unique($this->ruleTable($table->name), $field->name)
                ->ignore($record?->getKey(), $record?->getKeyName() ?? 'id')
                ->when(! $table->isExternal(), fn (Unique $rule): Unique => $rule->whereNull('deleted_at')));
        }

        return $component;
    }

    /**
     * Fortlaufende Felder: Vorschlag „höchster Wert + 1“ beim Anlegen. Mit Zählbereich (z. B. je
     * Bestellung) aus der vorgegebenen oder der gewählten Verknüpfung; wechselt die Verknüpfung, folgt
     * der Vorschlag, solange das Feld nicht von Hand geändert wurde.
     *
     * @param  list<Field>  $components
     * @param  array<string, mixed>  $preset
     */
    private function suggestSequences(CustomTable $table, array $components, array $preset): void
    {
        $byName = [];

        foreach ($components as $component) {
            $byName[$component->getName()] = $component;
        }

        $suggester = app(SequenceSuggester::class);

        foreach ($table->fields as $field) {
            if (! SequenceSuggester::applies($field) || ! isset($byName[$field->name])) {
                continue;
            }

            $scope = SequenceSuggester::scope($field);
            $next = static fn (mixed $scopeValue): ?int => $suggester->next($table, $field, $scopeValue);

            $byName[$field->name]->default(fn (Get $get): ?int => $next($scope === null ? null : ($preset[$scope] ?? $get($scope))));

            if ($scope !== null && ! array_key_exists($scope, $preset) && isset($byName[$scope])) {
                $name = $field->name;
                $byName[$scope]->live()->afterStateUpdated(static function (Get $get, Set $set, mixed $old, mixed $state, string $operation) use ($name, $next): void {
                    if ($operation !== 'create') {
                        return;
                    }

                    $current = $get($name);

                    // Nur einen eigenen (früheren) Vorschlag ersetzen, nie eine Eingabe.
                    if ($current === null || $current === '' || (int) $current === $next($old)) {
                        $set($name, $next($state));
                    }
                });
            }
        }
    }

    /**
     * Datums- und Zeitfelder mit „Mit heute/jetzt vorbelegen“: Vorgabe beim Anlegen, änderbar. Eine Vorgabe des
     * Aufrufs (z. B. aus einer Detailtabelle) hat Vorrang.
     *
     * @param  list<Field>  $components
     * @param  array<string, mixed>  $preset
     */
    private function prefillNow(CustomTable $table, array $components, array $preset): void
    {
        $byName = [];

        foreach ($components as $component) {
            $byName[$component->getName()] = $component;
        }

        foreach ($table->fields as $field) {
            if (NowDefault::applies($field) && isset($byName[$field->name]) && ! array_key_exists($field->name, $preset)) {
                $byName[$field->name]->default(static fn (): string => NowDefault::value($field));
            }
        }
    }

    /**
     * Vorgabe aus dem Master-Datensatz: beim Anlegen mit vorgegebener Verknüpfung sofort; bei Wahl oder
     * Wechsel der Verknüpfung (Anlegen und Bearbeiten), solange das Feld leer ist oder noch den
     * übernommenen Wert hat – eine eigene Eingabe bleibt.
     *
     * @param  list<Field>  $components
     * @param  array<string, mixed>  $preset
     */
    private function copyFromMaster(CustomTable $table, array $components, array $preset): void
    {
        $byName = [];

        foreach ($components as $component) {
            $byName[$component->getName()] = $component;
        }

        $relations = $table->fields->keyBy('name');

        foreach ($table->fields as $field) {
            [$relationName, $source] = CopySource::of($field) ?? [null, null];
            $target = $relationName === null ? null : ($relations[$relationName]?->optionsArray()['table'] ?? null);

            if (! is_string($target) || ! isset($byName[$field->name])) {
                continue;
            }

            $lookup = static fn (mixed $key): mixed => CopySource::lookup($target, (string) $source, $key);

            if (array_key_exists($relationName, $preset)) {
                $byName[$field->name]->default(fn (): mixed => $lookup($preset[$relationName]));

                continue;
            }

            if (! isset($byName[$relationName])) {
                continue;
            }

            $name = $field->name;
            $byName[$relationName]->live()->afterStateUpdated(static function (Get $get, Set $set, mixed $old, mixed $state) use ($name, $lookup): void {
                $current = $get($name);
                $previous = $lookup($old);

                if ($current === null || $current === '' || self::sameValue($current, $previous)) {
                    $set($name, $lookup($state));
                }
            });
        }
    }

    /** Vergleich Formularwert / Datenbankwert (Zahlen als Zahlen: „12.5“ = 12.50). */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($b === null) {
            return false;
        }

        return is_numeric($a) && is_numeric($b) ? (float) $a === (float) $b : (string) $a === (string) $b;
    }

    private function relationSelect(CustomField $field): Select
    {
        $options = $field->optionsArray();
        $targetName = (string) $options['table'];
        $target = static fn (): DynamicRecord => DynamicRecord::forTableName($targetName);
        $targetIsExternal = static fn (): bool => ! $target()->hasSoftDeleteColumn();

        return Select::make($field->name)
            ->searchable()
            // Suche über alle Anzeigefelder, z. B. Vor- oder Nachname.
            ->getSearchResultsUsing(fn (string $search): array => RelationDisplay::options(RelationDisplay::search($target()->newQuery(), $options, $search), $options, 50))
            ->options(fn (): array => RelationDisplay::options($target()->newQuery(), $options, 50))
            ->getOptionLabelUsing(fn (mixed $value): ?string => RelationDisplay::label($target()->newQuery()->withTrashed()->find($value), $options))
            ->rule(fn (): Exists => Rule::exists($this->ruleTable($targetName), $target()->getKeyName())
                ->when(! $targetIsExternal(), fn (Exists $rule): Exists => $rule->whereNull('deleted_at')));
    }

    /** "verbindung.schema.tabelle": Laravel trennt die Verbindung am ersten Punkt ab. */
    private function ruleTable(string $tableName): string
    {
        $model = DynamicRecord::forTableName($tableName);

        return $model->hasSoftDeleteColumn()
            ? config('dynamic_data.connection').'.'.$this->namespace->qualify($tableName)
            : $model->getConnectionName().'.'.$model->getTable();
    }
}
