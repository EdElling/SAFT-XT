<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\DetailRelations;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Rules\TableRule;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\IdentifierValidator;
use App\DynamicData\TableDefinition;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Support\Translations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bindet Tabellen und Views externer Datenbanken an (Metadaten, Rechte, Menü) und pflegt
 * Bezeichnungen und Sichtbarkeit. Die externe Datenbank wird dabei nie verändert.
 */
final class TableConnectorService
{
    /** Änderbare Eigenschaften der Anbindung; Quelle und Name sind fest. */
    private const array TABLE_ATTRIBUTES = ['label', 'description', 'icon', 'is_active', 'export_enabled', 'detail_field_ids', 'allow_create', 'allow_update', 'allow_delete'];

    /** Markierungen nach „Neu einlesen“ (in options): neue Spalte bzw. Spalte fehlt in der Datenbank. */
    public const string NEW = 'schema_new';

    public const string MISSING = 'schema_missing';

    private const array FIELD_ATTRIBUTES = ['label', 'show_in_table', 'show_in_form', 'is_searchable', 'is_active', 'is_read_only', 'sort_order'];

    public function __construct(
        private readonly SchemaInspector $inspector,
        private readonly IdentifierValidator $identifiers,
        private readonly DataPermissions $permissions,
        private readonly DataMenu $menu,
        private readonly SchemaComparison $comparison,
    ) {}

    /** Vorschlag für den internen Namen, z. B. „forschung_projekte“; eindeutig unter allen Tabellen. */
    public function suggestName(string $schema, string $table): string
    {
        $base = Str::limit(trim((string) preg_replace('/[^a-z0-9_]+/', '_', Str::lower(Str::ascii($schema.'_'.$table))), '_'), 56, '');
        $base = preg_match('/^[a-z]/', $base) === 1 ? $base : 't_'.$base;
        $name = $base;

        for ($i = 2; CustomTable::query()->where('name', $name)->exists(); $i++) {
            $name = $base.'_'.$i;
        }

        return $name;
    }

    /** Lesbare Bezeichnung aus einem Spalten- oder Tabellennamen: „projektleiter_id“ → „Projektleiter“. */
    public static function labelFor(string $name): string
    {
        $words = trim((string) preg_replace('/_id$/i', '', $name), '_');

        return Str::ucfirst(str_replace('_', ' ', $words === '' ? $name : $words));
    }

    /**
     * @param  array<string, mixed>  $data  name, label, description, menu_group (fehlt: „Daten“; leer: kein Menüeintrag)
     * @param  bool  $linkForeignKeys  Verknüpfungen gleich ermitteln; die Massenverknüpfung macht das einmal am Ende.
     */
    public function connect(DatabaseConnection $connection, string $schema, string $tableName, array $data, bool $linkForeignKeys = true): CustomTable
    {
        $name = (string) ($data['name'] ?? '');
        $this->identifiers->assertValidTableName($name);

        if (! $connection->is_active) {
            $this->fail(__('table_connector.errors.connection_inactive', ['connection' => $connection->name]));
        }

        if (CustomTable::query()->where('name', $name)->exists()) {
            $this->fail(__('tables.errors.table_exists', ['name' => $name]));
        }

        $info = $this->inspector->table($connection, $schema, $tableName)
            ?? $this->fail(__('table_connector.errors.unknown_table', ['table' => $schema.'.'.$tableName]));
        $columns = array_values(array_filter($this->inspector->columns($connection, $schema, $tableName), static fn (ColumnInfo $c): bool => $c->isSupported()));

        if ($columns === []) {
            $this->fail(__('table_connector.errors.no_columns', ['table' => $schema.'.'.$tableName]));
        }

        $imported = array_map(static fn (ColumnInfo $c): string => $c->name, $columns);
        // Schlüssel nur, wenn alle seine Spalten übernommen werden können.
        $keys = array_diff($info->keyColumns, $imported) === [] ? $info->keyColumns : [];
        $default = (string) config('locales.default');

        try {
            $table = DB::transaction(function () use ($connection, $info, $columns, $keys, $name, $data, $default): CustomTable {
                $table = CustomTable::create([
                    'app_id' => App::resolve($data['app_id'] ?? null)->id,
                    'name' => $name,
                    'source' => CustomTable::SOURCE_EXTERNAL,
                    'database_connection_id' => $connection->id,
                    'external_schema' => $info->schema,
                    'external_table' => $info->name,
                    'external_kind' => $info->kind,
                    'key_columns' => $keys,
                    'label' => Translations::isEmpty($label = Translations::clean($data['label'] ?? null)) ? [$default => self::labelFor($info->name)] : $label,
                    'description' => Translations::isEmpty($description = Translations::clean($data['description'] ?? null, 5000))
                        ? ($info->comment === null ? null : [$default => $info->comment])
                        : $description,
                    'is_active' => true,
                    'export_enabled' => false,
                ]);

                foreach ($columns as $position => $column) {
                    $table->fields()->create($this->fieldAttributes($column, $position, $default));
                }

                $this->permissions->ensure($table);

                $group = array_key_exists('menu_group', $data) ? $data['menu_group'] : DataMenu::NEW_DATA_GROUP;

                if (filled($group)) {
                    $this->menu->create($table, $group === DataMenu::NEW_DATA_GROUP ? $this->menu->defaultGroup() : (string) $group);
                }

                return $table;
            });
        } finally {
            DynamicRecord::forget($name);
        }

        $this->applyChecks($table);

        // Fremdschlüssel in beide Richtungen: von dieser Tabelle und auf diese Tabelle.
        if ($linkForeignKeys) {
            $this->applyForeignKeys($connection);
        }

        return $table->fresh('fields') ?? $table;
    }

    /** Spalten, die eine Verknüpfung tragen können: Ganzzahl oder Text (auch uuid). */
    public static function canRelate(CustomField $field): bool
    {
        return in_array(self::baseType($field), [FieldType::Integer, FieldType::String], true)
            && ! in_array($field->external_type, ['json', 'jsonb'], true);
    }

    /** Typ der Spalte selbst, auch wenn sie gerade eine Verknüpfung ist. */
    public static function baseType(CustomField $field): FieldType
    {
        return $field->type === FieldType::Relation
            ? FieldType::from((string) ($field->optionsArray()['base_type'] ?? FieldType::Integer->value))
            : $field->type;
    }

    /**
     * Mögliche Zieltabellen: angebundene Tabellen derselben Verbindung mit einzelnem Schlüssel.
     *
     * @return array<string, string> Name => Bezeichnung
     */
    public function relationTargets(CustomTable $table): array
    {
        return CustomTable::query()->external()
            ->where('database_connection_id', $table->database_connection_id)
            ->get()
            ->filter(fn (CustomTable $target): bool => $target->hasSingleKey())
            ->mapWithKeys(fn (CustomTable $target): array => [$target->name => (string) $target->label])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    /**
     * Wählbare Anzeigefelder der Zieltabelle: sichtbare Spalten, keine Verknüpfungen.
     *
     * @return array<string, string> Name => Bezeichnung
     */
    public function displayFieldOptions(?string $targetName): array
    {
        $target = $targetName === null ? null : CustomTable::query()->external()->with('fields')->where('name', $targetName)->first();

        return $target === null ? [] : $target->fields
            ->filter(fn (CustomField $f): bool => $f->is_active && $f->type !== FieldType::Relation)
            ->mapWithKeys(fn (CustomField $f): array => [$f->name => $f->label.' ('.$f->name.')'])
            ->all();
    }

    /** Spaltennamen, die typischerweise einen Datensatz benennen. */
    private const string NAMING_COLUMN = '/(name|titel|title|bezeichnung|label|designation|nome|titolo)/i';

    /**
     * Vorschlag: ein benennendes Textfeld (Name, Titel, Bezeichnung …), sonst das erste Textfeld,
     * sonst die erste andere Spalte, zuletzt der Schlüssel.
     */
    public function defaultDisplayField(CustomTable $target): ?string
    {
        $key = $target->keyColumn();
        $fields = $target->fields->filter(fn (CustomField $f): bool => $f->is_active && $f->type !== FieldType::Relation && $f->name !== $key);
        $texts = $fields->filter(fn (CustomField $f): bool => in_array($f->type, [FieldType::String, FieldType::Text], true) && ! ($f->optionsArray()['text_only'] ?? false));

        return $texts->first(fn (CustomField $f): bool => preg_match(self::NAMING_COLUMN, $f->name) === 1)?->name
            ?? $texts->first()?->name
            ?? $fields->first()?->name
            ?? $key;
    }

    /**
     * Verknüpfung setzen oder (mit null) entfernen. Eine von Hand entfernte Verknüpfung wird beim
     * Übernehmen von Fremdschlüsseln nicht wieder gesetzt.
     */
    /**
     * @param  list<string>|string|null  $displayFields  ein oder mehrere Anzeigefelder; leer = Vorschlag
     */
    public function setRelation(CustomField $field, ?string $targetName, array|string|null $displayFields, bool $automatic = false, ?string $separator = null): CustomField
    {
        $table = $field->custom_table;
        $this->assertExternal($table);
        $options = $field->optionsArray();
        $base = self::baseType($field);

        if (blank($targetName)) {
            unset($options['table'], $options['display_field'], $options['display_fields'], $options['separator'], $options['base_type']);
            $options['relation_removed'] = ! $automatic;
            $attributes = ['type' => $base->value, 'options' => $options, 'is_read_only' => $field->is_read_only || ($options['text_only'] ?? false)];
        } else {
            if (! self::canRelate($field)) {
                $this->fail(__('table_connector.errors.relation_type', ['column' => $field->name]));
            }

            $target = CustomTable::query()->external()->with('fields')->where('name', (string) $targetName)->first();

            if ($target === null || $target->database_connection_id !== $table->database_connection_id || ! $target->hasSingleKey()) {
                $this->fail(__('table_connector.errors.relation_target', ['table' => (string) $targetName]));
            }

            $fields = array_values(array_filter(array_map('strval', (array) $displayFields), static fn (string $f): bool => $f !== ''));
            $fields = $fields === [] ? array_filter([$this->defaultDisplayField($target)]) : $fields;

            foreach ($fields as $displayField) {
                if (! array_key_exists($displayField, $this->displayFieldOptions($target->name))) {
                    $this->fail(__('tables.errors.unknown_display_field', ['field' => $displayField, 'table' => $target->name]));
                }
            }

            unset($options['relation_removed']);
            $attributes = [
                // Wird eine uuid-Spalte zur Verknüpfung, ist sie über die Auswahl beschreibbar (sonst nur Text).
                'is_read_only' => $field->type !== FieldType::Relation && ($options['text_only'] ?? false) && ! $field->is_generated ? false : $field->is_read_only,
                'type' => FieldType::Relation->value,
                'options' => RelationDisplay::store([...$options, 'table' => $target->name, 'base_type' => $base->value], array_values($fields), $separator),
                // Über den Anzeigewert wird nicht gesucht; technische Werte (uuid) werden mit Anzeigewert lesbar.
                'is_searchable' => false,
                'show_in_table' => $field->show_in_table || ($automatic && ($options['text_only'] ?? false)),
            ];
        }

        try {
            $field->fill($attributes)->save();
        } finally {
            DynamicRecord::forget($table->name);
        }

        return $field;
    }

    /** Vorschlag aus dem Fremdschlüssel der Datenbank, wenn die Zieltabelle angebunden ist. */
    public function suggestRelation(CustomField $field): ?array
    {
        $table = $field->custom_table;
        $connection = $table->databaseConnection;

        if ($connection === null || ! self::canRelate($field)) {
            return null;
        }

        try {
            $keys = $this->inspector->foreignKeys($connection, (string) $table->external_schema, (string) $table->external_table);
        } catch (Throwable) {
            return null;
        }

        foreach ($keys as $key) {
            if ($key->column === $field->name && ($target = $this->connectedTarget($connection, $key, $table->app_id)) !== null) {
                return ['table' => $target->name, 'display_field' => $this->defaultDisplayField($target)];
            }
        }

        return null;
    }

    /**
     * Übernimmt Fremdschlüssel der Datenbank als Verknüpfungen, wo die Zieltabelle angebunden ist und
     * die Spalte noch keine (und keine von Hand entfernte) Verknüpfung hat.
     *
     * @return int Zahl der neu gesetzten Verknüpfungen
     */
    public function applyForeignKeys(DatabaseConnection $connection): int
    {
        $count = 0;
        $tables = CustomTable::query()->external()->with('fields')->where('database_connection_id', $connection->id)->get();

        foreach ($tables as $table) {
            try {
                $keys = $this->inspector->foreignKeys($connection, (string) $table->external_schema, (string) $table->external_table);
            } catch (Throwable) {
                continue;
            }

            foreach ($keys as $key) {
                $field = $table->fields->firstWhere('name', $key->column);
                $options = $field?->optionsArray() ?? [];

                if ($field === null || $field->type === FieldType::Relation || ($options['relation_removed'] ?? false) || ! self::canRelate($field)) {
                    continue;
                }

                $target = $this->connectedTarget($connection, $key, $table->app_id);

                if ($target !== null) {
                    $this->setRelation($field, $target->name, $this->defaultDisplayField($target), automatic: true);
                    $count++;
                }
            }
        }

        return $count;
    }

    /** Angebundene Zieltabelle eines Fremdschlüssels – nur in derselben App (B-107). */
    private function connectedTarget(DatabaseConnection $connection, ForeignKeyInfo $key, ?int $appId): ?CustomTable
    {
        return CustomTable::query()->external()->with('fields')
            ->where('database_connection_id', $connection->id)
            ->where('app_id', $appId)
            ->where('external_schema', $key->foreignSchema)
            ->where('external_table', $key->foreignTable)
            ->get()
            ->first(fn (CustomTable $t): bool => $t->keyColumn() === $key->foreignColumn);
    }

    /**
     * @param  array<string, mixed>  $data  label, description, icon, is_active, export_enabled, key_columns
     */
    public function update(CustomTable $table, array $data): CustomTable
    {
        $this->assertExternal($table);

        try {
            return DB::transaction(function () use ($table, $data): CustomTable {
                $attributes = array_intersect_key($data, array_flip(self::TABLE_ATTRIBUTES));

                if (array_key_exists('label', $attributes)) {
                    $label = Translations::clean($attributes['label']);
                    $attributes['label'] = Translations::isEmpty($label) ? $table->getTranslations('label') : $label;
                }

                if (array_key_exists('description', $attributes)) {
                    $description = Translations::clean($attributes['description'], 5000);
                    $attributes['description'] = Translations::isEmpty($description) ? null : $description;
                }

                if (array_key_exists('detail_field_ids', $attributes)) {
                    $attributes['detail_field_ids'] = DetailRelations::resolve($table->name, array_map('intval', (array) $attributes['detail_field_ids']))->pluck('id')->all();
                }

                if (array_key_exists('key_columns', $data)) {
                    $attributes['key_columns'] = $this->keyColumns($table, $data['key_columns']);
                }

                $table->fill($attributes);

                // Schreiben nur freischalten, wo es möglich ist (Tabelle mit Schlüssel, Verbindung nicht „nur lesen“)
                // und die Datenbank den Schlüssel als eindeutig garantiert.
                $writing = array_filter(CustomTable::WRITE_ACTIONS, fn (string $flag): bool => (bool) $table->{$flag});
                $check = $writing !== [] && ($table->isDirty('key_columns') || array_filter($writing, fn (string $flag): bool => $table->isDirty($flag)) !== []);

                if ($check && ! $table->canBeWritable()) {
                    $this->fail(__('table_connector.errors.not_writable', ['table' => (string) $table->label]));
                }

                if ($check && ! $this->keyGuaranteed($table)) {
                    $this->fail(__('table_connector.errors.key_not_unique', ['columns' => implode(', ', $table->keyColumns())]));
                }

                $table->save();
                $this->menu->sync($table);

                return $table;
            });
        } finally {
            DynamicRecord::forget($table->name);
        }
    }

    /**
     * @param  array<string, mixed>  $data  label, show_in_table, show_in_form, is_searchable, is_active, sort_order
     */
    public function updateField(CustomField $field, array $data): CustomField
    {
        $this->assertExternal($field->custom_table);
        $attributes = array_intersect_key($data, array_flip(self::FIELD_ATTRIBUTES));

        if (array_key_exists('label', $attributes)) {
            $label = Translations::clean($attributes['label']);
            $attributes['label'] = Translations::isEmpty($label) ? $field->getTranslations('label') : $label;
        }

        // Ein Text, der nur angezeigt wird, eignet sich nicht für die Suche (z. B. json, uuid).
        if (($attributes['is_searchable'] ?? false) && ! in_array($field->type, [FieldType::String, FieldType::Text], true)) {
            $attributes['is_searchable'] = false;
        }

        // Generierte Spalten und reine Textanzeigen (uuid, json …) bleiben immer nur lesbar.
        if (array_key_exists('is_read_only', $attributes) && ($field->is_generated || TableDefinition::isTextOnly($field))) {
            $attributes['is_read_only'] = true;
        }

        // Eine Spalte, die in der Datenbank fehlt, kann nicht wieder eingeblendet werden.
        $options = $field->optionsArray();

        if ($options[self::MISSING] ?? false) {
            $attributes['is_active'] = false;
        }

        // Wer die neue Spalte bearbeitet hat, hat sie gesehen.

        if ($options[self::NEW] ?? false) {
            unset($options[self::NEW]);
            $attributes['options'] = $options;
        }

        try {
            $field->fill($attributes)->save();
        } finally {
            DynamicRecord::forget($field->custom_table->name);
        }

        return $field;
    }

    /**
     * Übernimmt das aktuelle Schema der externen Tabelle („Neu einlesen“): neue Spalten werden sichtbar
     * angelegt und als neu markiert, fehlende ausgeblendet und als fehlend markiert (nicht gelöscht),
     * Typen und Regeln angepasst, der Primärschlüssel aktualisiert und neue Fremdschlüssel übernommen.
     *
     * @return list<string> Hinweise zu Folgen (entfernte Verknüpfungen, gesperrtes Schreiben …)
     */
    public function refresh(CustomTable $table): array
    {
        $this->assertExternal($table);
        $difference = $this->comparison->compare($table);

        if ($difference->tableMissing) {
            $this->fail(__('table_connector.schema.table_missing'));
        }

        $notices = [];

        try {
            DB::transaction(function () use ($table, $difference, &$notices): void {
                $default = (string) config('locales.default');
                $position = (int) $table->fields()->max('sort_order');

                foreach ($difference->added as $column) {
                    $attributes = $this->fieldAttributes($column, ++$position, $default);
                    $attributes['options'] = [...$attributes['options'], self::NEW => true];
                    $table->fields()->create($attributes);
                }

                foreach ($difference->returned as $field) {
                    $options = $field->optionsArray();
                    unset($options[self::MISSING]);
                    $field->fill(['options' => $options, 'is_active' => true])->save();
                }

                foreach ($difference->changed as $change) {
                    $notices = [...$notices, ...$this->applyColumn($change['field']->fresh() ?? $change['field'], $change['column'])];
                }

                foreach ($difference->missing as $field) {
                    $options = $field->optionsArray();
                    unset($options[self::NEW]);
                    $field->fill(['options' => [...$options, self::MISSING => true], 'is_active' => false])->save();
                }

                if ($difference->keyColumns !== null || $difference->keyNotUnique) {
                    $notices = [...$notices, ...$this->applyKey($table, $difference->keyColumns ?? $table->keyColumns())];
                }

                $notices = [...$notices, ...$this->repairRelationsTo($table->fresh('fields') ?? $table)];
            });
        } finally {
            DynamicRecord::forget($table->name);
        }

        $this->applyChecks($table->fresh() ?? $table);

        if ($table->databaseConnection !== null) {
            $this->applyForeignKeys($table->databaseConnection);
        }

        return $notices;
    }

    /** Optionen am Feld: Werteliste bzw. Min./Max. aus CHECK-Regeln der Datenbank. */
    public const string DB_VALUES = 'db_values';

    public const string DB_RULES = 'db_rules';

    /**
     * Übernimmt die CHECK-Regeln der externen Tabelle: Wertelisten und Min./Max. an die Felder,
     * Vergleiche zweier Spalten als Regeln der Tabelle; alle Regeln für Abgleich und Fehlermeldungen.
     * Eigene Regeln (Table-Designer) und Einstellungen der Felder bleiben unberührt.
     */
    public function applyChecks(CustomTable $table): void
    {
        $connection = $table->databaseConnection;

        if ($connection === null) {
            return;
        }

        $checks = $this->inspector->checkConstraints($connection, (string) $table->external_schema, (string) $table->external_table);
        $fields = $table->fields()->get()->keyBy('name');
        $types = $fields->filter(fn (CustomField $f): bool => ! TableDefinition::isTextOnly($f))
            ->map(fn (CustomField $f): string => self::baseType($f)->value)->all();
        $translator = $connection->driver->checkTranslator();
        [$values, $ranges, $rules, $stored] = [[], [], [], []];

        foreach ($checks as $check) {
            $translation = $translator->translate($check, $types);

            if ($translation?->values !== null) {
                $column = $translation->column;
                $values[$column] = isset($values[$column]) ? array_values(array_intersect($values[$column], $translation->values)) : $translation->values;
            } elseif ($translation?->rule !== null) {
                $rules[] = $translation->rule->toArray();
            } elseif ($translation !== null) {
                $range = $ranges[$translation->column] ?? [];
                $ranges[$translation->column] = array_filter([
                    'min' => $translation->min === null ? ($range['min'] ?? null) : max($range['min'] ?? $translation->min, $translation->min),
                    'max' => $translation->max === null ? ($range['max'] ?? null) : min($range['max'] ?? $translation->max, $translation->max),
                ], static fn (mixed $v): bool => $v !== null);
            }

            $stored[] = ['name' => $check->name, 'clause' => $check->clause, 'columns' => $check->columns, 'translated' => $translation !== null];
        }

        DB::transaction(function () use ($table, $fields, $values, $ranges, $rules, $stored): void {
            foreach ($fields as $name => $field) {
                /** @var CustomField $field */
                $options = $field->optionsArray();
                $wanted = array_filter([self::DB_VALUES => $values[$name] ?? null, self::DB_RULES => $ranges[$name] ?? null], static fn (mixed $v): bool => $v !== null && $v !== []);
                $current = array_intersect_key($options, array_flip([self::DB_VALUES, self::DB_RULES]));

                if ($current != $wanted) {
                    $field->fill(['options' => [...array_diff_key($options, $current), ...$wanted]])->save();
                }
            }

            $own = array_values(array_filter((array) ($table->validation_rules ?? []), static fn (mixed $r): bool => ($r['source'] ?? null) !== TableRule::SOURCE_DATABASE));
            $table->forceFill(['validation_rules' => [...$own, ...$rules], 'check_constraints' => $stored])->save();
        });

        DynamicRecord::forget($table->name);
    }

    /** Endgültig entfernen: nur Felder, deren Spalte in der Datenbank fehlt. */
    public function removeMissingField(CustomField $field): void
    {
        $table = $field->custom_table;
        $this->assertExternal($table);

        if (! ($field->optionsArray()[self::MISSING] ?? false)) {
            $this->fail(__('table_connector.errors.not_missing', ['column' => $field->name]));
        }

        try {
            DB::transaction(function () use ($table, $field): void {
                $table->fill(['detail_field_ids' => array_values(array_diff(array_map('intval', (array) ($table->detail_field_ids ?? [])), [$field->id]))]);
                $table->save();

                // Detailbeziehungen anderer Tabellen über dieses Feld.
                CustomTable::query()->whereJsonContains('detail_field_ids', $field->id)->get()
                    ->each(fn (CustomTable $t): bool => $t->fill(['detail_field_ids' => array_values(array_diff(array_map('intval', (array) $t->detail_field_ids), [$field->id]))])->save());

                $field->delete();
            });
        } finally {
            DynamicRecord::forget($table->name);
        }
    }

    /**
     * Typ, Pflicht, Vorgabe, Eindeutigkeit und „generiert“ aus der Spalte übernehmen; Bezeichnung,
     * Sichtbarkeit und Verknüpfung bleiben, soweit sie zum neuen Typ passen.
     *
     * @return list<string>
     */
    private function applyColumn(CustomField $field, ColumnInfo $column): array
    {
        /** @var FieldType $type */
        $type = $column->type;
        $options = array_diff_key($field->optionsArray(), array_flip(['length', 'precision', 'scale']));
        $options = [...$options, ...$column->options, 'db_required' => $column->isRequired(), 'db_default' => $column->default !== null, 'db_uuid' => $column->hasUuidDefault(), 'text_only' => $column->textOnly];
        $isRelation = $field->type === FieldType::Relation;

        if ($isRelation) {
            $options['base_type'] = $type->value;
        }

        $field->fill([
            'type' => $isRelation ? FieldType::Relation->value : $type->value,
            'external_type' => Str::limit($column->externalType, 128, ''),
            'options' => $options,
            'is_unique' => $column->unique,
            'is_generated' => $column->generated,
            'is_read_only' => $field->is_read_only || $column->generated || ($column->textOnly && ! $isRelation),
            'is_searchable' => $field->is_searchable && ! $isRelation && in_array($type, [FieldType::String, FieldType::Text], true) && ! $column->textOnly,
        ])->save();

        if ($isRelation && ! self::canRelate($field)) {
            $this->setRelation($field, null, null, automatic: true);

            return [__('table_connector.schema.relation_dropped', ['column' => $field->name])];
        }

        return [];
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function applyKey(CustomTable $table, array $keys): array
    {
        $table->fill(['key_columns' => $keys]);
        $notices = [];

        if (! $table->canBeWritable() || ! $this->keyGuaranteed($table)) {
            foreach (CustomTable::WRITE_ACTIONS as $flag) {
                if ($table->{$flag}) {
                    $table->{$flag} = false;
                    $notices = [__('table_connector.schema.writing_disabled')];
                }
            }
        }

        $table->save();

        return $notices;
    }

    /**
     * Verknüpfungen auf diese Tabelle an fehlende Anzeigefelder bzw. einen fehlenden Schlüssel anpassen.
     *
     * @return list<string>
     */
    private function repairRelationsTo(CustomTable $target): array
    {
        $notices = [];
        $active = $target->fields->where('is_active', true)->pluck('name')->all();
        $referencing = CustomField::query()->with('custom_table')->where('type', FieldType::Relation->value)->get()
            ->filter(fn (CustomField $f): bool => ($f->optionsArray()['table'] ?? null) === $target->name && $f->custom_table->isExternal());

        foreach ($referencing as $field) {
            $options = $field->optionsArray();

            if (! $target->hasSingleKey()) {
                $this->setRelation($field, null, null, automatic: true);
                $notices[] = __('table_connector.schema.relation_dropped', ['column' => $field->custom_table->name.'.'.$field->name]);

                continue;
            }

            $display = RelationDisplay::fields($options);
            $remaining = array_values(array_intersect($display, $active));

            if ($remaining !== $display) {
                $this->setRelation($field, $target->name, $remaining, separator: RelationDisplay::separator($options));
                $notices[] = __('table_connector.schema.display_changed', ['column' => $field->custom_table->name.'.'.$field->name]);
            }
        }

        return $notices;
    }

    /** Entfernt nur die Anbindung in SAFT (Metadaten, Rechte, Menüeinträge). */
    public function disconnect(CustomTable $table): void
    {
        $this->assertExternal($table);

        try {
            DB::transaction(function () use ($table): void {
                // Verknüpfungen anderer angebundener Tabellen auf diese fallen auf ihren Spaltentyp zurück.
                DetailRelations::referencing($table->name)
                    ->filter(fn (CustomField $f): bool => $f->custom_table_id !== $table->id)
                    ->each(fn (CustomField $f): CustomField => $this->setRelation($f, null, null, automatic: true));
                $table->fields()->get()->each->delete();
                $this->permissions->remove($table);
                $this->menu->remove($table);
                // Datenstände gehören zur Anbindung: Kopie und Stände mit entfernen.
                app(SnapshotService::class)->drop($table);
                $table->delete();
            });
        } finally {
            DynamicRecord::forget($table->name);
        }
    }

    /**
     * Löscht eine Datenbankverbindung samt allen darüber angebundenen Tabellen (je Tabelle wie
     * disconnect()). Sind Tabellen angebunden, muss der Name der Verbindung exakt bestätigt werden. Mit $keepTables
     * bleiben die Anbindungen ohne Verbindung und inaktiv erhalten (keine Bestätigung nötig).
     * Alles oder nichts; die externe Datenbank bleibt unverändert.
     *
     * @return int Anzahl der entfernten Anbindungen
     */
    public function deleteConnection(DatabaseConnection $connection, ?string $confirmation = null, bool $keepTables = false): int
    {
        $tables = $connection->customTables()->get();

        if (! $keepTables && $tables->isNotEmpty() && trim((string) $confirmation) !== $connection->name) {
            $this->fail(__('db_connections.delete.confirmation', ['name' => $connection->name]));
        }

        DB::transaction(function () use ($connection, $tables, $keepTables): void {
            // Behalten (B-107, Etappe 4b): Anbindungen ohne Verbindung und inaktiv; später „Verbindung zuordnen“.
            $tables->each(fn (CustomTable $table) => $keepTables
                ? $table->forceFill(['database_connection_id' => null, 'is_active' => false])->save()
                : $this->disconnect($table));
            $connection->delete();
        });

        app(ConnectionRegistry::class)->forget($connection);

        return $tables->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldAttributes(ColumnInfo $column, int $position, string $locale): array
    {
        /** @var FieldType $type */
        $type = $column->type;
        $readOnly = $column->generated || $column->textOnly;

        return [
            'name' => $column->name,
            'label' => [$locale => self::labelFor($column->name)],
            'type' => $type->value,
            'external_type' => Str::limit($column->externalType, 128, ''),
            // Pflicht laut Datenbank: erst beim Bearbeiten wichtig (spätere Etappe).
            'options' => [...$column->options, 'db_required' => $column->isRequired(), 'db_default' => $column->default !== null, 'db_uuid' => $column->hasUuidDefault(), 'text_only' => $column->textOnly],
            'is_required' => false,
            'is_unique' => $column->unique,
            'is_searchable' => in_array($type, [FieldType::String, FieldType::Text], true) && ! $column->textOnly,
            // Technische Werte (uuid, json …) nur in den Details; in der Liste einblendbar.
            'show_in_table' => ! $column->textOnly,
            'show_in_form' => true,
            'is_active' => true,
            'is_read_only' => $readOnly,
            'is_generated' => $column->generated,
            'sort_order' => $position,
        ];
    }

    /**
     * Gewählte Schlüsselspalten in der Reihenfolge der Spaltenliste.
     *
     * @return list<string>
     */
    private function keyColumns(CustomTable $table, mixed $columns): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('strval', (array) $columns), static fn (string $c): bool => $c !== '')));
        $known = $table->fields()->where('is_active', true)->pluck('name')->all();

        foreach ($wanted as $column) {
            if (! in_array($column, $known, true)) {
                $this->fail(__('table_connector.errors.unknown_key', ['column' => $column]));
            }
        }

        return array_values(array_intersect($known, $wanted));
    }

    /**
     * Garantiert die Datenbank die Eindeutigkeit genau der Schlüsselspalten (Primärschlüssel oder
     * eindeutiger Index)? Nur dann darf über SAFT geschrieben werden.
     */
    public function keyGuaranteed(CustomTable $table): bool
    {
        $keys = $table->keyColumns();
        $connection = $table->databaseConnection;

        if ($keys === [] || $connection === null) {
            return false;
        }

        sort($keys);

        try {
            return in_array($keys, $this->inspector->uniqueKeys($connection, (string) $table->external_schema, (string) $table->external_table), true);
        } catch (Throwable) {
            return false;
        }
    }

    private function assertExternal(CustomTable $table): void
    {
        if (! $table->isExternal()) {
            $this->fail(__('table_connector.errors.not_external', ['name' => $table->name]));
        }
    }

    private function fail(string $message): never
    {
        throw new BusinessRuleException($message, 'external.table_connector');
    }
}
