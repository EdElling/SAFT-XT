<?php

declare(strict_types=1);

namespace App\Services;

use App\Apps\AppInterfaces;
use App\Apps\CurrentApp;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\DetailRelations;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\Printer;
use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberConversion;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Numbering\NumberSequences;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\Operand;
use App\DynamicData\Rules\RuleFieldTypes;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Rules\TableRule;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\IdentifierValidator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\Database\DatabaseErrorClassifier;
use App\Exceptions\Database\DatabaseErrorType;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\User;
use App\Models\WorkItem;
use App\Plugins\PluginDependencies;
use App\Support\Translations;
use App\Work\Types\RecomputeWork;
use App\Work\WorkService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Einziger Weg, Tabellen des Table-Designers zu ändern: hält Metadaten (custom_tables,
 * custom_fields im Namensraum public) und physische Tabellen (app_data) synchron.
 *
 * - Jede Operation unter Sperre je Tabelle (parallele Admins).
 * - Transaktionen verschachtelt: Metadaten (Hauptverbindung) committen zuerst, die DDL
 *   (app_data-Verbindung, PostgreSQL: transaktional) danach. MySQL kann DDL nicht zurückrollen:
 *   Nach einem Fehler wird die Tabelle an die (zurückgerollten) Metadaten angeglichen.
 *   Restrisiko-Kontrolle: php artisan app-data:verify.
 * - Destruktive Änderungen nur nach Deaktivierung und Bestätigung per Namen.
 * - Permissions data.<tabelle>.* entstehen/verschwinden mit der Tabelle; Menüeinträge
 *   folgen Bezeichnung/Aktiv-Status und werden mit der Tabelle entfernt.
 */
final class DynamicSchemaService
{
    private const int LOCK_SECONDS = 60;

    private const int LOCK_WAIT_SECONDS = 10;

    /** Typwechsel, die bestehende Daten verlustfrei übernehmen (from => [to, ...]). */
    private const array SAFE_CONVERSIONS = [
        'string' => ['text'],
        'select' => ['string', 'text'],
        'integer' => ['decimal', 'string', 'text'],
        'decimal' => ['string', 'text'],
        'date' => ['datetime'],
    ];

    public function __construct(
        private readonly DynamicDataNamespace $namespace,
        private readonly IdentifierValidator $identifiers,
        private readonly FieldDefinitionNormalizer $normalizer,
        private readonly DatabaseErrorClassifier $errors,
        private readonly DataPermissions $permissions,
        private readonly DataMenu $menu,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, label, description, icon, is_active, export_enabled
     * @param  list<array<string, mixed>>  $fields
     */
    public function createTable(array $data, array $fields = []): CustomTable
    {
        $name = (string) ($data['name'] ?? '');
        $this->identifiers->assertValidTableName($name);

        return $this->mutate($name, function () use ($name, $data, $fields): CustomTable {
            if (CustomTable::query()->where('name', $name)->exists()) {
                $this->fail(__('tables.errors.table_exists', ['name' => $name]));
            }

            if ($this->schema()->hasTable($this->namespace->qualify($name))) {
                $this->fail(__('tables.errors.table_without_metadata', ['name' => $name]));
            }

            $table = CustomTable::create([
                'app_id' => App::resolve($data['app_id'] ?? null)->id,
                'name' => $name,
                'label' => Translations::isEmpty($label = Translations::clean($data['label'] ?? null)) ? $name : $label,
                'description' => Translations::isEmpty($description = Translations::clean($data['description'] ?? null, 5000)) ? null : $description,
                'icon' => $data['icon'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'export_enabled' => (bool) ($data['export_enabled'] ?? false),
                'import_enabled' => (bool) ($data['import_enabled'] ?? false),
            ]);

            $created = [];

            foreach (array_values($fields) as $position => $fieldData) {
                $attributes = $this->normalizer->normalize(['sort_order' => $position, ...$fieldData], $table);

                if (isset($created[$attributes['name']])) {
                    $this->fail(__('tables.errors.duplicate_field', ['name' => $attributes['name']]));
                }

                $this->assertIndexable($attributes);

                $created[$attributes['name']] = $table->fields()->create($attributes);
            }

            $this->ensureNumberSequences($created);

            $this->schema()->create($this->namespace->qualify($name), function (Blueprint $blueprint) use ($table, $created): void {
                $blueprint->id();

                foreach ($created as $field) {
                    $field->type->addColumn($blueprint, $field->name, $field->optionsArray());
                }

                $blueprint->unsignedBigInteger('created_by')->nullable()->index("dt_{$table->id}_created_by");
                $blueprint->unsignedBigInteger('updated_by')->nullable();
                $blueprint->timestamps();
                $blueprint->softDeletes();
            });

            foreach ($created as $field) {
                $this->createConstraints($table, $field);
            }

            $this->permissions->ensure($table);

            return $table->load('fields');
        });
    }

    /**
     * Anzeige-Eigenschaften und Details (Master-Detail); der technische Name ist unveränderlich (Permissions,
     * Audit-Zuordnung, Verknüpfungen hängen daran).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTable(CustomTable $table, array $data): CustomTable
    {
        $this->assertDesigner($table);

        if (array_key_exists('name', $data) && $data['name'] !== $table->name) {
            $this->fail(__('tables.errors.name_fixed'));
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $table->is_active) {
            $this->assertNotRequiredByPlugin($table->name);
            $this->assertNotUsedByOtherApps($table);
        }

        if (array_key_exists('detail_field_ids', $data)) {
            // Nur gültige Verknüpfungen auf diese Tabelle (Reihenfolge bleibt).
            $data['detail_field_ids'] = DetailRelations::resolve($table->name, array_map('intval', (array) $data['detail_field_ids']))
                ->pluck('id')
                ->all();
        }

        if (array_key_exists('validation_rules', $data)) {
            // Eigene Regeln (Formularzeilen) prüfen; Regeln anderer Herkunft bleiben.
            $foreign = array_values(array_filter($table->tableRules(), static fn (TableRule $r): bool => $r->source !== TableRule::SOURCE_DESIGNER));
            $data['validation_rules'] = [
                ...array_map(static fn (TableRule $r): array => $r->toArray(), $foreign),
                ...app(TableRuleNormalizer::class)->normalize($table->loadMissing('fields'), (array) $data['validation_rules']),
            ];
        }

        return $this->mutate($table->name, function () use ($table, $data): CustomTable {
            $table->fill(array_intersect_key($data, array_flip(['label', 'description', 'icon', 'is_active', 'export_enabled', 'import_enabled', 'detail_field_ids', 'validation_rules'])))->save();
            $this->menu->sync($table);

            return $table;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addField(CustomTable $table, array $data): CustomField
    {
        $this->assertDesigner($table);

        $field = $this->mutate($table->name, function () use ($table, $data): CustomField {
            $attributes = $this->normalizer->normalize(
                ['sort_order' => (int) $table->fields()->max('sort_order') + 1, ...$data],
                $table,
            );

            $this->assertColumnNameAvailable($table, $attributes['name']);
            $this->assertIndexable($attributes);

            $field = $table->fields()->create($attributes);
            $this->ensureNumberSequences([$field]);

            $this->schema()->table($this->namespace->qualify($table->name), function (Blueprint $blueprint) use ($field): void {
                $field->type->addColumn($blueprint, $field->name, $field->optionsArray());
            });

            $this->createConstraints($table, $field);

            return $field;
        });

        if ($field->type === FieldType::Computed) {
            $this->recompute($table);
        }

        return $field;
    }

    /**
     * Änderungen an einem Feld: Umbenennen (Daten bleiben), sichere Typwechsel, Indizes,
     * reine Metadaten (Label, Flags, Validierung, Sortierung, aktiv/inaktiv).
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * Ginge die Änderung eines Felds durch (Angaben gültig, Typ- bzw. Optionsänderung mit den vorhandenen Daten möglich)?
     * Nur lesend – für den Änderungsplan beim Aktualisieren einer App (B-107, Etappe 6). Null oder die Meldung.
     *
     * @param  array<string, mixed>  $data
     */
    public function fieldChangeProblem(CustomField $field, array $data): ?string
    {
        try {
            $table = $field->customTable;
            $new = $this->normalizer->normalize([...$this->fieldState($field), ...$data], $table);
            $this->assertIndexable($new);
            $this->assertNumberChange($table, $field, $new);
            $this->columnChangeStrategy($table, $field, $new);

            return null;
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }
    }

    /** @return array<string, mixed> aktueller Stand eines Felds im Eingabeformat von updateField */
    private function fieldState(CustomField $field): array
    {
        return [
            ...$field->only(['name', 'is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form', 'is_active', 'sort_order']),
            // Alle Sprachen, sonst würde die Ersatzsprache als aktuelle Sprache gespeichert.
            'label' => $field->getTranslations('label'),
            'type' => $field->type,
            'options' => $field->optionsArray(),
            'validation_rules' => (array) ($field->validation_rules ?? []),
        ];
    }

    public function updateField(CustomField $field, array $data): CustomField
    {
        $this->assertDesigner($field->custom_table);

        $table = $field->customTable;

        $before = [$field->type, $field->optionsArray(), $field->is_active];

        $updated = $this->mutate($table->name, function () use ($field, $table, $data): CustomField {
            $new = $this->normalizer->normalize([...$this->fieldState($field), ...$data], $table);

            $renamed = $new['name'] !== $field->name;
            $this->assertIndexable($new);

            // Felder, die ein Plugin braucht: Name, Typ und „aktiv“ bleiben.
            if ($renamed || $new['type'] !== $field->type || (! $new['is_active'] && $field->is_active)) {
                $this->assertNotRequiredByPlugin($table->name, $field->name);
                $this->assertNotUsedByOtherApps($table, $field->name);
            }

            $fromType = $field->type;
            $numberBefore = $field->optionsArray();
            $numberChange = $this->assertNumberChange($table, $field, $new);

            // Bezugsdatum eines Nummernkreises: bleibt ein aktives Pflichtfeld vom Typ Datum bzw. Datum/Zeit.
            if (! in_array($new['type'], [FieldType::Date, FieldType::DateTime], true) || ! $new['is_required'] || ! $new['is_active']) {
                $this->assertNotNumberDate($table, $field->name);
            }

            // Gruppe eines Nummernkreises (N4): bleibt ein aktives Pflichtfeld vom Typ Auswahl; neue Wahlen brauchen ein
            // Kurzzeichen (ihr Wert oder ein Eintrag im Nummernkreis).
            if ($new['type'] !== FieldType::Select || ! $new['is_required'] || ! $new['is_active']) {
                $this->assertNotNumberGroup($table, $field->name);
            } else {
                $this->assertNumberGroupChoices($table, $field->name, $new['options']);
            }

            // „Vergeben, wenn“ eines Nummernkreises (N3): das Feld bleibt aktiv und vergleichbar (keine Datei).
            if (! $new['is_active'] || $new['type'] === FieldType::File) {
                $this->assertNotNumberCondition($table, $field->name);
            }

            // Vor jeder DDL prüfen: MySQL könnte die bereits ausgeführten Schritte nicht zurückrollen.
            if ($new['is_unique'] && ! $field->is_unique && $this->hasActiveDuplicates($table, $field->name)) {
                $this->fail(__('tables.errors.not_unique', ['name' => $field->name]));
            }

            // Regeln vergleichen nur passende Typen (Zahl, Datum, Text, Ja/Nein).
            if (RuleFieldTypes::group($new['type']) !== RuleFieldTypes::group($field->type) && $this->rulesUsing($table, $field->name) !== []) {
                $this->fail(__('tables.errors.used_in_rule_type', ['name' => $field->name]));
            }

            // Formeln rechnen nur mit Zahlen (auch Summen über Kind-Datensätze); Verknüpfungen für Summen bleiben Verknüpfungen.
            $numeric = in_array($new['type']->valueType($new['options']), [FieldType::Integer, FieldType::Decimal], true);
            $formula = $numeric ? null : ($this->formulasUsing($table, $field->name)->first()
                ?? $this->aggregatesOver($table->name, $field->name, 'value')->first());

            if ($field->type === FieldType::Relation && $new['type'] !== FieldType::Relation) {
                $formula ??= $this->aggregatesOver($table->name, $field->name, 'via')->first();
            }

            if ($formula !== null) {
                $this->fail(__('tables.errors.used_in_formula', ['name' => $field->name, 'field' => $formula->custom_table->name.'.'.$formula->name]));
            }

            // Felder, die aus diesem Feld übernehmen, müssen zum neuen Typ passen.
            foreach ($this->copiesFrom($table, $field->name) as $copy) {
                if (! CopySource::compatible($copy->type, $new['type'])) {
                    $this->fail(__('tables.errors.used_as_copy_source_type', ['name' => $field->name, 'field' => $copy->custom_table->name.'.'.$copy->name]));
                }
            }
            $strategy = $this->columnChangeStrategy($table, $field, $new);
            $structural = $renamed || $strategy !== 'none'
                || $new['is_unique'] !== $field->is_unique
                || $new['is_sortable'] !== $field->is_sortable;

            if ($renamed) {
                $this->assertColumnNameAvailable($table, $new['name'], $field);
            }

            $qualified = $this->namespace->qualify($table->name);

            if ($structural) {
                $this->dropConstraints($table, $field);
            }

            if ($renamed) {
                $this->schema()->table($qualified, fn (Blueprint $b) => $b->renameColumn($field->name, $new['name']));
                $this->renameDisplayFieldReferences($table, $field->name, $new['name']);
                $this->renameRuleReferences($table, $field->name, $new['name']);
                $this->updateSuggestScopes($table, $field->name, $new['name']);
                $this->renameNumberDateReferences($table, $field->name, $new['name']);
                $this->renameNumberGroupReferences($table, $field->name, $new['name']);
                $this->renameNumberConditionReferences($table, $field->name, $new['name']);
                $this->renameCopyReferences($table, $field->name, $new['name']);
                $this->renameFormulaReferences($table, $field->name, $new['name']);
                $this->renameAggregateReferences($table->name, $field->name, $new['name']);
            }

            match ($strategy) {
                'change' => $this->schema()->table($qualified, fn (Blueprint $b) => $new['type']->addColumn($b, $new['name'], $new['options'])->change()),
                'recreate' => $this->recreateColumn($qualified, $new['name'], $new['type'], $new['options']),
                default => null,
            };

            // Keine Verknüpfung mehr: Zählbereich „je …“ und Vorgaben aus dem Master entfallen.
            if ($field->type === FieldType::Relation && $new['type'] !== FieldType::Relation) {
                $this->updateSuggestScopes($table, $new['name'], null);
                $this->dropCopiesVia($table, $new['name']);
            }

            $field->fill($new)->save();

            if ($field->type === FieldType::Select) {
                $this->syncNumberGroupCodes($table, $field);
            }

            // Umgestellt: Ganzzahlen formatieren, Zähler je Periode auf den höchsten passenden Wert (vor dem Index).
            if ($numberChange && $field->type === FieldType::Number) {
                $this->ensureNumberSequences([$field]);
                app(NumberConversion::class)->apply($table, $field, $fromType);
            } elseif ($fromType === FieldType::Number && $field->type === FieldType::Number && NumberPattern::fromOptions($numberBefore)->differs(NumberPattern::fromOptions($field->optionsArray()))) {
                // Muster oder Neubeginn geändert: Vorhandene Nummern, die zum neuen Muster passen, heben ihren Zähler an –
                // sonst zöge die nächste Vergabe eine schon vorhandene Nummer und müsste weiterzählen.
                app(NumberConversion::class)->apply($table, $field, FieldType::Number);
            }

            if ($structural) {
                $this->createConstraints($table, $field);
            }

            return $field;
        });

        // Kein Nummernkreis mehr: seine Zähler entfallen.
        if ($before[0] === FieldType::Number && $updated->type !== FieldType::Number) {
            app(NumberSequences::class)->forget((int) $updated->id);
        }

        // Formel, Ergebnisart oder Aktivierung eines berechneten Felds geändert: alle Werte neu berechnen.
        if ($updated->type === FieldType::Computed && [$updated->type, $updated->optionsArray(), $updated->is_active] != $before) {
            $this->recompute($table);
        }

        return $updated;
    }

    /**
     * Nummernkreise (B-135): Umstellen von Text, mehrzeiligem Text, Ganzzahl und Auswahl auch mit Werten (N2), wenn die
     * nicht gelöschten Werte eindeutig sind; zurück zu Text immer. Andere Typwechsel von und zu „Nummernkreis“ nur bei
     * leerer Spalte (auch im Papierkorb). Gibt zurück, ob der Typ von oder zu „Nummernkreis“ wechselt.
     *
     * @param  array<string, mixed>  $new  normalisierte Attribute
     */
    private function assertNumberChange(CustomTable $table, CustomField $field, array $new): bool
    {
        $from = $field->type;

        if ($new['type'] === $from || ! in_array(FieldType::Number, [$new['type'], $from], true)) {
            return false;
        }

        $convertible = $new['type'] === FieldType::Number
            ? in_array($from, NumberConversion::sources(), true)
            : in_array($new['type'], NumberConversion::targets(), true);

        if (! $convertible && ! $this->columnIsEmpty($table, $field->name)) {
            $this->fail(__('numbering.errors.type_change', ['name' => $field->name]));
        }

        if ($new['type'] === FieldType::Number) {
            $analysis = app(NumberConversion::class)->analyze($table, $field->name, NumberPattern::fromOptions($new['options']), $from);

            if ($analysis->hasDuplicates()) {
                $this->fail(__('numbering.errors.duplicates', ['name' => $field->name, 'list' => implode(', ', $analysis->duplicates)]));
            }
        }

        return true;
    }

    /** So lange rechnet die Nachberechnung direkt im Request mit, bevor sie dem Hintergrund überlassen wird. */
    private const float RECOMPUTE_BUDGET = 5.0;

    private ?WorkItem $lastRecompute = null;

    /**
     * Nachberechnung aller Werte als Arbeit „Berechnete Felder neu berechnen“ (Fundament 2, F3b): kleine Tabellen sind
     * fertig, bevor der Aufruf zurückkehrt; grosse rechnet der Browser oder der Scheduler weiter – lastRecompute() sagt,
     * ob die Arbeit noch offen ist. Läuft als die angemeldete Person in ihrer App.
     */
    private function recompute(CustomTable $table): void
    {
        $user = auth()->user() instanceof User ? auth()->user() : null;
        $this->lastRecompute = app(WorkService::class)->startAndAdvance(RecomputeWork::KEY, ['tables' => [$table->name]], $user, app(CurrentApp::class)->id($user), self::RECOMPUTE_BUDGET);
    }

    /** Die Nachberechnung, die addField() oder updateField() zuletzt gestartet hat; null, wenn keine nötig war. */
    public function lastRecompute(): ?WorkItem
    {
        return $this->lastRecompute;
    }

    /**
     * Regeln der Tabelle, die das Feld verwenden.
     *
     * @return list<TableRule>
     */
    private function rulesUsing(CustomTable $table, string $field): array
    {
        return array_values(array_filter(
            $table->fresh()?->tableRules() ?? [],
            static fn (TableRule $r): bool => in_array($field, [$r->left->fieldName(), $r->right->fieldName()], true),
        ));
    }

    /**
     * Fortlaufende Vorschläge „je Verknüpfung“ folgen der Umbenennung; null: Zählbereich entfällt
     * (Verknüpfung gelöscht oder keine Verknüpfung mehr), es wird über die ganze Tabelle gezählt.
     */
    /**
     * Zählertabelle der Nummernkreise anlegen, sobald das erste Feld dieses Typs entsteht (B-135).
     *
     * @param  iterable<CustomField>  $fields
     */
    private function ensureNumberSequences(iterable $fields): void
    {
        foreach ($fields as $field) {
            if ($field->type === FieldType::Number) {
                app(NumberSequences::class)->ensureTable();

                return;
            }
        }
    }

    /** Nummernkreise der Tabelle, deren Bezugsdatum dieses Feld ist. */
    private function assertNotNumberDate(CustomTable $table, string $field): void
    {
        $number = $table->fields()->where('type', FieldType::Number->value)->get()
            ->first(static fn (CustomField $f): bool => ($f->optionsArray()[NumberPattern::DATE_FIELD] ?? null) === $field);

        if ($number !== null) {
            $this->fail(__('numbering.errors.date_field_in_use', ['name' => $field, 'field' => $number->name]));
        }
    }

    /** Nummernkreise der Tabelle, deren Gruppe dieses Feld ist (N4). */
    private function assertNotNumberGroup(CustomTable $table, string $field): void
    {
        if (($number = $this->numbersGroupedBy($table, $field)->first()) !== null) {
            $this->fail(__('numbering.errors.group_field_in_use', ['name' => $field, 'field' => $number->name]));
        }
    }

    /**
     * Neue Wahlen eines Gruppenfelds (N4): Ohne Eintrag im Nummernkreis ist ihr Wert das Kurzzeichen und muss gültig
     * sein – sonst scheiterte später die Vergabe.
     *
     * @param  array<string, mixed>  $options  normalisierte Optionen des Auswahlfelds
     */
    private function assertNumberGroupChoices(CustomTable $table, string $field, array $options): void
    {
        foreach ($this->numbersGroupedBy($table, $field) as $number) {
            $codes = (array) ($number->optionsArray()[NumberPattern::GROUP_CODES] ?? []);

            foreach ((array) ($options['options'] ?? []) as $choice) {
                $value = (string) (is_array($choice) ? ($choice['value'] ?? '') : $choice);

                if (! isset($codes[$value]) && ! NumberPattern::validCode($value)) {
                    $this->fail(__('numbering.errors.group_choice_in_use', ['value' => $value, 'name' => $field, 'field' => $number->name, 'max' => NumberPattern::MAX_CODE]));
                }
            }
        }
    }

    /** Neue Wahlen eines Gruppenfelds bekommen im Nummernkreis ihren Wert als Kurzzeichen; entfernte behalten ihres. */
    private function syncNumberGroupCodes(CustomTable $table, CustomField $select): void
    {
        foreach ($this->numbersGroupedBy($table, $select->name) as $number) {
            $options = $number->optionsArray();
            $codes = (array) ($options[NumberPattern::GROUP_CODES] ?? []);
            $missing = array_diff(FieldDefinitionNormalizer::choiceValues($select), array_map(strval(...), array_keys($codes)));

            if ($missing !== []) {
                $number->forceFill(['options' => [...$options, NumberPattern::GROUP_CODES => $codes + array_combine($missing, $missing)]])->save();
            }
        }
    }

    private function renameNumberGroupReferences(CustomTable $table, string $from, string $to): void
    {
        foreach ($this->numbersGroupedBy($table, $from) as $number) {
            $number->forceFill(['options' => [...$number->optionsArray(), NumberPattern::GROUP_FIELD => $to]])->save();
        }
    }

    /** @return Collection<int, CustomField> */
    private function numbersGroupedBy(CustomTable $table, string $field): Collection
    {
        return $table->fields()->where('type', FieldType::Number->value)->get()
            ->filter(static fn (CustomField $f): bool => ($f->optionsArray()[NumberPattern::GROUP_FIELD] ?? null) === $field)->values();
    }

    /** Nummernkreise der Tabelle, deren „Vergeben, wenn“ dieses Feld liest (N3). */
    private function assertNotNumberCondition(CustomTable $table, string $field): void
    {
        $number = $table->fields()->where('type', FieldType::Number->value)->get()
            ->first(static fn (CustomField $f): bool => $f->name !== $field && in_array($field, NumberCondition::fields($f->optionsArray()), true));

        if ($number !== null) {
            $this->fail(__('numbering.errors.condition_field_in_use', ['name' => $field, 'field' => $number->name]));
        }
    }

    /** Die Bedingung speichert Felder per Name und folgt deshalb der Umbenennung (wie Formeln). */
    private function renameNumberConditionReferences(CustomTable $table, string $from, string $to): void
    {
        foreach ($table->fields()->where('type', FieldType::Number->value)->get() as $number) {
            $options = $number->optionsArray();

            if (! in_array($from, NumberCondition::fields($options), true)) {
                continue;
            }

            $ast = Printer::map((array) $options[NumberCondition::AST], static fn (array $node): array => ($node['field'] ?? null) === $from ? [...$node, 'field' => $to] : $node);
            $number->forceFill(['options' => [...$options, NumberCondition::AST => $ast, NumberCondition::OPTION => Printer::print($ast)]])->save();
        }
    }

    private function renameNumberDateReferences(CustomTable $table, string $from, string $to): void
    {
        foreach ($table->fields()->where('type', FieldType::Number->value)->get() as $number) {
            $options = $number->optionsArray();

            if (($options[NumberPattern::DATE_FIELD] ?? null) === $from) {
                $number->forceFill(['options' => [...$options, NumberPattern::DATE_FIELD => $to]])->save();
            }
        }
    }

    private function updateSuggestScopes(CustomTable $table, string $from, ?string $to): void
    {
        foreach ($table->fields()->get() as $other) {
            $options = $other->optionsArray();

            if (($options[SequenceSuggester::SCOPE] ?? null) !== $from) {
                continue;
            }

            if ($to === null) {
                unset($options[SequenceSuggester::SCOPE]);
            } else {
                $options[SequenceSuggester::SCOPE] = $to;
            }

            $other->forceFill(['options' => $options])->save();
        }
    }

    /**
     * Berechnete Felder der Tabelle, deren Formel das Feld verwendet.
     *
     * @return Collection<int, CustomField>
     */
    private function formulasUsing(CustomTable $table, string $field): Collection
    {
        return $table->fields()->get()
            ->filter(fn (CustomField $f): bool => $f->type === FieldType::Computed && $f->name !== $field
                && in_array($field, FormulaAnalyzer::fields((array) ($f->optionsArray()['ast'] ?? [])), true))
            ->values();
    }

    /**
     * Berechnete Felder (auch anderer Tabellen), die über diese Tabelle summieren; mit Feld nur die, die es
     * verwenden – als summierten Wert ('value'), als Verknüpfung ('via') oder beides ('any').
     *
     * @param  'any'|'value'|'via'  $role
     * @return Collection<int, CustomField>
     */
    private function aggregatesOver(string $table, ?string $field = null, string $role = 'any'): Collection
    {
        $uses = static fn (array $a): bool => $field === null
            || ($role !== 'value' && $a['via'] === $field)
            || ($role !== 'via' && in_array($field, $a['fields'], true));

        return CustomField::query()->with('custom_table')->where('type', FieldType::Computed->value)->get()
            ->filter(fn (CustomField $f): bool => collect(FormulaAnalyzer::aggregates((array) ($f->optionsArray()['ast'] ?? [])))
                ->contains(fn (array $a): bool => $a['table'] === $table && $uses($a)))
            ->values();
    }

    /** Summen speichern Feld und Verknüpfung der Kind-Tabelle per Name und folgen der Umbenennung. */
    private function renameAggregateReferences(string $table, string $from, string $to): void
    {
        foreach ($this->aggregatesOver($table, $from) as $formula) {
            $options = $formula->optionsArray();
            $ast = Printer::map((array) $options['ast'], static function (array $node) use ($table, $from, $to): array {
                if (($node['agg'] ?? null) === null || $node['table'] !== $table) {
                    return $node;
                }

                foreach (['field', 'via'] as $key) {
                    if (($node[$key] ?? null) === $from) {
                        $node[$key] = $to;
                    }
                }

                // Felder im Ausdruck je Kind-Datensatz.
                if (isset($node['expr'])) {
                    $node['expr'] = Printer::map((array) $node['expr'], static fn (array $n): array => ($n['field'] ?? null) === $from ? [...$n, 'field' => $to] : $n);
                }

                return $node;
            });
            $formula->forceFill(['options' => [...$options, 'ast' => $ast, 'formula' => Printer::print($ast)]])->save();
        }
    }

    /** Formeln speichern Felder per Name und folgen deshalb der Umbenennung. */
    private function renameFormulaReferences(CustomTable $table, string $from, string $to): void
    {
        foreach ($this->formulasUsing($table, $from) as $formula) {
            $options = $formula->optionsArray();
            $ast = Printer::map((array) $options['ast'], static fn (array $node): array => ($node['field'] ?? null) === $from ? [...$node, 'field' => $to] : $node);
            $formula->forceFill(['options' => [...$options, 'ast' => $ast, 'formula' => Printer::print($ast)]])->save();
        }
    }

    /**
     * Felder (auch anderer Tabellen), die beim Anlegen den Wert dieses Felds übernehmen.
     *
     * @return Collection<int, CustomField>
     */
    private function copiesFrom(CustomTable $source, string $field): Collection
    {
        return CustomField::query()->with('custom_table')->where('options->'.CopySource::OPTION, 'like', '%.'.$field)->get()
            ->filter(function (CustomField $copy) use ($source, $field): bool {
                [$relation, $from] = CopySource::of($copy) ?? [null, null];
                $via = $relation === null ? null : $copy->custom_table->fields()->where('name', $relation)->first();

                return $from === $field && ($via?->optionsArray()['table'] ?? null) === $source->name;
            })
            ->values();
    }

    /**
     * Umbenennung: als Verknüpfung (Felder derselben Tabelle) und als Quellfeld (Felder anderer Tabellen).
     */
    private function renameCopyReferences(CustomTable $table, string $from, string $to): void
    {
        $sources = $this->copiesFrom($table, $from);

        foreach ($table->fields()->get() as $copy) {
            [$relation, $field] = CopySource::of($copy) ?? [null, null];

            if ($relation === $from) {
                $copy->forceFill(['options' => [...$copy->optionsArray(), CopySource::OPTION => $to.'.'.$field]])->save();
            }
        }

        foreach ($sources as $copy) {
            [$relation] = CopySource::of($copy->fresh() ?? $copy) ?? [null];
            $copy->forceFill(['options' => [...$copy->optionsArray(), CopySource::OPTION => $relation.'.'.$to]])->save();
        }
    }

    /** Verknüpfung entfällt: Vorgaben über sie werden entfernt. */
    private function dropCopiesVia(CustomTable $table, string $relation): void
    {
        foreach ($table->fields()->get() as $copy) {
            if ((CopySource::of($copy)[0] ?? null) === $relation) {
                $options = $copy->optionsArray();
                unset($options[CopySource::OPTION]);
                $copy->forceFill(['options' => $options])->save();
            }
        }
    }

    /** Regeln speichern Felder per Name und folgen deshalb der Umbenennung. */
    private function renameRuleReferences(CustomTable $table, string $from, string $to): void
    {
        $table->refresh();
        $rename = static fn (array $operand): array => ($operand['kind'] ?? null) === Operand::FIELD && ($operand['field'] ?? null) === $from
            ? [...$operand, 'field' => $to]
            : $operand;
        $rules = array_map(static fn (array $r): array => [...$r, 'left' => $rename((array) $r['left']), 'right' => $rename((array) $r['right'])], (array) ($table->validation_rules ?? []));

        if ($rules !== (array) ($table->validation_rules ?? [])) {
            $table->forceFill(['validation_rules' => $rules])->save();
        }
    }

    /**
     * Zahl der vorhandenen (nicht gelöschten) Datensätze, die eine Regel verletzen – über den
     * Query Builder, ohne Dialekt-SQL.
     */
    public function ruleViolations(CustomTable $table, TableRule $rule): int
    {
        $negated = ['=' => '<>', '<>' => '=', '<' => '>=', '<=' => '>', '>' => '<=', '>=' => '<'][$rule->operator];
        $left = (string) $rule->left->fieldName();
        $query = DynamicRecord::forTable($table)->newQuery();

        $compare = static fn ($q) => $rule->right->isField()
            ? $q->whereColumn($left, $negated, (string) $rule->right->fieldName())
            : $q->where($left, $negated, $rule->right->value);

        if ($rule->whenFilled) {
            $query->whereNotNull($left)->when($rule->right->isField(), fn ($q) => $q->whereNotNull((string) $rule->right->fieldName()))->where($compare);
        } else {
            $query->where(fn ($q) => $q->whereNull($left)
                ->when($rule->right->isField(), fn ($q) => $q->orWhereNull((string) $rule->right->fieldName()))
                ->orWhere($compare));
        }

        return $query->count();
    }

    /** Verknüpfungen speichern ihre Anzeigefelder per Name und folgen deshalb der Umbenennung. */
    private function renameDisplayFieldReferences(CustomTable $table, string $from, string $to): void
    {
        $this->relationsDisplaying($table, $from)->each(function (CustomField $relation) use ($from, $to): void {
            $options = $relation->optionsArray();
            $fields = array_map(static fn (string $f): string => $f === $from ? $to : $f, RelationDisplay::fields($options));
            $relation->options = RelationDisplay::store($options, $fields, RelationDisplay::separator($options));
            $relation->save();
        });
    }

    /**
     * Verknüpfungen auf diese Tabelle, die das Feld anzeigen (auch als eines von mehreren).
     *
     * @return Collection<int, CustomField>
     */
    private function relationsDisplaying(CustomTable $table, string $field): Collection
    {
        return CustomField::query()
            ->where('type', FieldType::Relation->value)
            ->where('options->table', $table->name)
            ->get()
            ->filter(fn (CustomField $relation): bool => in_array($field, RelationDisplay::fields($relation->optionsArray()), true))
            ->values();
    }

    /** Physisches Löschen: Feld muss deaktiviert sein, Bestätigung durch Feldnamen. */
    public function dropField(CustomField $field, string $confirmation): void
    {
        $this->assertDesigner($field->custom_table);

        $table = $field->customTable;

        $this->mutate($table->name, function () use ($field, $table, $confirmation): void {
            $this->assertDestructionConfirmed($field->is_active, $field->name, $confirmation, __('tables.what.field'));
            $this->assertNotRequiredByPlugin($table->name, $field->name);
            $this->assertNotUsedByOtherApps($table, $field->name);

            if ($this->rulesUsing($table, $field->name) !== []) {
                $this->fail(__('tables.errors.used_in_rule', ['name' => $field->name]));
            }

            if (($formula = $this->formulasUsing($table, $field->name)->first()) !== null) {
                $this->fail(__('tables.errors.used_in_formula', ['name' => $field->name, 'field' => $formula->name]));
            }

            if (($formula = $this->aggregatesOver($table->name, $field->name)->first()) !== null) {
                $this->fail(__('tables.errors.used_in_formula', ['name' => $field->name, 'field' => $formula->custom_table->name.'.'.$formula->name]));
            }

            if (($copy = $this->copiesFrom($table, $field->name)->first()) !== null) {
                $this->fail(__('tables.errors.used_as_copy_source', ['name' => $field->name, 'field' => $copy->custom_table->name.'.'.$copy->name]));
            }

            $this->assertNotNumberDate($table, $field->name);
            $this->assertNotNumberGroup($table, $field->name);
            $this->assertNotNumberCondition($table, $field->name);

            $usedAsDisplay = $this->relationsDisplaying($table, $field->name)->isNotEmpty();

            if ($usedAsDisplay) {
                $this->fail(__('tables.errors.used_as_display_field', ['name' => $field->name]));
            }

            $this->dropConstraints($table, $field);
            $this->schema()->table($this->namespace->qualify($table->name), fn (Blueprint $b) => $b->dropColumn($field->name));
            $this->updateSuggestScopes($table, $field->name, null);
            $this->dropCopiesVia($table, $field->name);
            $field->delete();
        });

        // Dateien erst nach erfolgreichem Löschen (bei einem Fehler bleibt alles, wie es war).
        if ($field->type === FieldType::File) {
            app(RecordFiles::class)->deleteForField($table, $field->name);
        }

        // Zähler eines Nummernkreises gehen mit dem Feld (B-135).
        if ($field->type === FieldType::Number) {
            app(NumberSequences::class)->forget((int) $field->id);
        }
    }

    /** Physisches Löschen: Tabelle muss deaktiviert sein, Bestätigung durch Tabellennamen. */
    public function dropTable(CustomTable $table, string $confirmation): void
    {
        $this->assertDesigner($table);
        // Die Einträge entfernt die Datenbank mit der Tabelle; die Dateien danach (nur bei Erfolg).
        $files = RecordFile::query()->where('custom_table_id', $table->id)->get();
        $numbers = $table->fields()->where('type', FieldType::Number->value)->pluck('id')->all();

        $this->mutate($table->name, function () use ($table, $confirmation): void {
            $this->assertDestructionConfirmed($table->is_active, $table->name, $confirmation, __('tables.what.table'));
            $this->assertNotRequiredByPlugin($table->name);
            $this->assertNotUsedByOtherApps($table);

            $referencedBy = CustomField::query()
                ->where('type', FieldType::Relation->value)
                ->where('options->table', $table->name)
                ->where('custom_table_id', '!=', $table->id)
                ->with('custom_table')
                ->get();

            if ($referencedBy->isNotEmpty()) {
                $this->fail(__('tables.errors.still_referenced', ['fields' => $referencedBy->map(fn (CustomField $f): string => $f->custom_table->name.'.'.$f->name)->implode(', ')]));
            }

            if (($formula = $this->aggregatesOver($table->name)->first()) !== null) {
                $this->fail(__('tables.errors.used_in_aggregate', ['name' => $table->name, 'field' => $formula->custom_table->name.'.'.$formula->name]));
            }

            $this->schema()->dropIfExists($this->namespace->qualify($table->name));
            $table->fields()->get()->each->delete();
            $this->permissions->remove($table);
            $this->menu->remove($table);
            $table->delete();
        });

        app(RecordFiles::class)->removeStored($files);

        foreach ($numbers as $id) {
            app(NumberSequences::class)->forget((int) $id);
        }
    }

    /**
     * Was das gemeinsame Löschen dieser Tabellen verhindert (B-107, App entfernen): Plugins, die eine davon brauchen,
     * Verknüpfungen oder Summen aus Tabellen ausserhalb. Verweise untereinander sind erlaubt.
     *
     * @param  Collection<int, CustomTable>  $tables
     * @return list<string> Meldungen; leer = möglich
     */
    public function dropTablesBlockers(Collection $tables): array
    {
        $names = $tables->pluck('name')->all();
        $ids = $tables->pluck('id')->all();
        $blockers = [];
        $dependencies = app(PluginDependencies::class);

        foreach ($names as $name) {
            if (($plugins = $dependencies->usedBy($name)) !== []) {
                $blockers[] = __('tables.errors.table_required_by_plugin', ['name' => $name, 'plugins' => $dependencies->names($plugins)]);
            }
        }

        $outside = CustomField::query()
            ->where('type', FieldType::Relation->value)
            ->whereIn('options->table', $names)
            ->whereNotIn('custom_table_id', $ids)
            ->with('custom_table')
            ->get();

        if ($outside->isNotEmpty()) {
            $blockers[] = __('tables.errors.still_referenced', ['fields' => $outside->map(fn (CustomField $f): string => $f->custom_table->name.'.'.$f->name)->implode(', ')]);
        }

        foreach ($names as $name) {
            $formula = $this->aggregatesOver($name)->first(fn (CustomField $f): bool => ! in_array($f->custom_table_id, $ids, true));

            if ($formula !== null) {
                $blockers[] = __('tables.errors.used_in_aggregate', ['name' => $name, 'field' => $formula->custom_table->name.'.'.$formula->name]);
            }
        }

        return $blockers;
    }

    /**
     * Löscht mehrere Designer-Tabellen gemeinsam samt Daten und Dateien (B-107, App entfernen): zuerst die
     * Fremdschlüssel untereinander, dann jede Tabelle wie dropTable(). Wiederholbar: Bereits Entferntes wird übersprungen.
     *
     * @param  Collection<int, CustomTable>  $tables
     */
    public function dropTables(Collection $tables): void
    {
        foreach ($tables as $table) {
            $this->assertDesigner($table);
        }

        if (($blockers = $this->dropTablesBlockers($tables)) !== []) {
            $this->fail(implode(' ', $blockers));
        }

        // 1. Fremdschlüssel untereinander, damit sich jede Tabelle einzeln löschen lässt.
        foreach ($tables as $table) {
            $relations = $table->fields()->where('type', FieldType::Relation->value)->get();
            $qualified = $this->namespace->qualify($table->name);

            if ($relations->isEmpty() || ! $this->schema()->hasTable($qualified)) {
                continue;
            }

            $existing = array_column($this->schema()->getForeignKeys($qualified), 'name');

            $this->mutate($table->name, function () use ($relations, $qualified, $existing): void {
                foreach ($relations as $field) {
                    if (in_array($field->indexName('fk'), $existing, true)) {
                        $this->schema()->table($qualified, fn (Blueprint $b) => $b->dropForeign($field->indexName('fk')));
                    }
                }
            });
        }

        // 2. Tabellen mit Metadaten, Rechten, Menüeinträgen und Dateien.
        foreach ($tables as $table) {
            $files = RecordFile::query()->where('custom_table_id', $table->id)->get();
            $numbers = $table->fields()->where('type', FieldType::Number->value)->pluck('id')->all();

            $this->mutate($table->name, function () use ($table): void {
                $this->schema()->dropIfExists($this->namespace->qualify($table->name));
                $table->fields()->get()->each->delete();
                $this->permissions->remove($table);
                $this->menu->remove($table);
                $table->delete();
            });

            app(RecordFiles::class)->removeStored($files);

            foreach ($numbers as $id) {
                app(NumberSequences::class)->forget((int) $id);
            }
        }
    }

    // --- intern ------------------------------------------------------------------------

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function mutate(string $tableName, Closure $work): mixed
    {
        return Cache::lock('dynamic-schema:'.$tableName, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($work, $tableName) {
            $metadataThenDdl = fn () => DB::connection()->transaction($work);
            $dialect = $this->namespace->dialect();

            try {
                if ($dialect->supportsTransactionalDdl()) {
                    return $this->namespace->connection()->transaction($metadataThenDdl);
                }

                $before = $this->snapshot($tableName);

                try {
                    return $metadataThenDdl();
                } catch (Throwable $e) {
                    $this->restore($tableName, $before);

                    throw $e;
                }
            } catch (QueryException $e) {
                if ($dialect->isRowSizeError($e)) {
                    $this->fail(__('tables.errors.row_too_large'));
                }

                throw $e;
            } finally {
                DynamicRecord::forget($tableName);
            }
        });
    }

    /** Angebundene externe Tabellen ändert nur der Table-Connector, nie per DDL. */
    private function assertDesigner(CustomTable $table): void
    {
        if ($table->isExternal()) {
            $this->fail(__('tables.errors.external_table', ['name' => $table->name]));
        }
    }

    private function schema(): Builder
    {
        return $this->namespace->connection()->getSchemaBuilder();
    }

    /**
     * @param  array<string, mixed>  $new  normalisierte Attribute
     * @return 'none'|'change'|'recreate'
     */
    private function columnChangeStrategy(CustomTable $table, CustomField $field, array $new): string
    {
        // Berechnete Felder zählen als ihre Ergebnisart (Ganz- oder Dezimalzahl).
        $old = $field->optionsArray();
        $opt = $new['options'];
        /** @var FieldType $to */
        $to = $new['type']->valueType($opt);
        $from = $field->valueType();

        $safe = match (true) {
            $from === $to => match ($from) {
                // Kürzer nur, wenn alle vorhandenen Werte hineinpassen.
                FieldType::String => $opt['length'] === $old['length'] ? null : ($opt['length'] > $old['length'] || $this->overLength($table, $field->name, (int) $opt['length'])[0] === 0),
                // Nur Stellen zählen; andere Optionen (z. B. Vorgabe aus dem Master) sind Metadaten.
                FieldType::Decimal => [$opt['precision'], $opt['scale']] == [$old['precision'], $old['scale']] ? null : ($opt['scale'] >= $old['scale'] && $opt['precision'] - $opt['scale'] >= $old['precision'] - $old['scale']),
                FieldType::Relation => $opt['table'] === $old['table'] ? null : false,
                default => null, // z. B. Select-Optionen: nur Metadaten
            },
            // Mehrzeilig → einzeilig: nur, wenn alle vorhandenen Werte in die Länge passen.
            $from === FieldType::Text && $to === FieldType::String => $this->overLength($table, $field->name, (int) $opt['length'])[0] === 0,
            // Auswahl → kürzerer Text (z. B. Nummernkreis mit 60 Zeichen): nur, wenn alle Werte hineinpassen.
            $from === FieldType::Select && $to === FieldType::String && $opt['length'] < FieldType::SELECT_LENGTH => $this->overLength($table, $field->name, (int) $opt['length'])[0] === 0,
            in_array($to->value, self::SAFE_CONVERSIONS[$from->value] ?? [], true) => $this->fitsWithoutLoss($from, $old, $to, $opt),
            default => false,
        };

        if ($safe === null) {
            return 'none';
        }

        if ($safe) {
            return 'change';
        }

        if ($this->columnIsEmpty($table, $field->name)) {
            return 'recreate';
        }

        if ($to === FieldType::String && in_array($from, [FieldType::String, FieldType::Text], true)) {
            [$count, $longest] = $this->overLength($table, $field->name, (int) $opt['length']);
            $this->fail(trans_choice('tables.errors.too_long', $count, ['name' => $field->name, 'count' => $count, 'length' => (int) $opt['length'], 'longest' => $longest]));
        }

        $this->fail(__('tables.errors.type_change', ['name' => $field->name, 'from' => $from->label(), 'to' => $to->label()]));
    }

    /**
     * Werte, die länger als $length Zeichen sind (gezählt in PHP, seitenweise; auch gelöschte Datensätze).
     *
     * @return array{0: int, 1: int} Anzahl, Länge des längsten Werts
     */
    private function overLength(CustomTable $table, string $column, int $length): array
    {
        $count = 0;
        $longest = 0;

        foreach ($this->namespace->connection()->table($this->namespace->qualify($table->name))->whereNotNull($column)->select(['id', $column])->lazyById(1000) as $row) {
            $size = mb_strlen((string) $row->{$column});
            $longest = max($longest, $size);
            $count += (int) ($size > $length);
        }

        return [$count, $longest];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function fitsWithoutLoss(FieldType $from, array $old, FieldType $to, array $new): bool
    {
        return match (true) {
            $to === FieldType::String && $from === FieldType::Select => $new['length'] >= FieldType::SELECT_LENGTH,
            $to === FieldType::String && $from === FieldType::Integer => $new['length'] >= 20,
            $to === FieldType::String && $from === FieldType::Decimal => $new['length'] >= $old['precision'] + 2,
            $to === FieldType::Decimal && $from === FieldType::Integer => $new['precision'] - $new['scale'] >= 19,
            default => true,
        };
    }

    private function columnIsEmpty(CustomTable $table, string $column): bool
    {
        // Ohne SoftDelete-Scope: auch gelöschte Datensätze enthalten Daten.
        return ! $this->namespace->connection()->table($this->namespace->qualify($table->name))->whereNotNull($column)->exists();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recreateColumn(string $qualified, string $column, FieldType $type, array $options): void
    {
        $this->schema()->table($qualified, fn (Blueprint $b) => $b->dropColumn($column));
        $this->schema()->table($qualified, fn (Blueprint $b) => $type->addColumn($b, $column, $options));
    }

    /**
     * @param  list<string>  $existing  bereits vorhandene Indizes/Fremdschlüssel (beim Angleichen)
     */
    private function createConstraints(CustomTable $table, CustomField $field, array $existing = []): void
    {
        $qualified = $this->namespace->qualify($table->name);
        $missing = static fn (string $name): bool => ! in_array($name, $existing, true);

        $this->schema()->table($qualified, function (Blueprint $blueprint) use ($field, $missing): void {
            // Index vor dem Fremdschlüssel: MySQL legt sonst einen eigenen Index dafür an.
            if ($field->needsIndex() && $missing($field->indexName('idx'))) {
                $blueprint->index([$field->name], $field->indexName('idx'));
            }

            if ($field->type === FieldType::Relation && $missing($field->indexName('fk'))) {
                $blueprint->foreign($field->name, $field->indexName('fk'))
                    ->references('id')
                    ->on($this->namespace->qualify((string) $field->optionsArray()['table']))
                    ->nullOnDelete();
            }
        });

        if ($field->is_unique && $missing($field->indexName('uq'))) {
            try {
                $this->namespace->dialect()->createActiveUniqueIndex($this->namespace->connection(), $qualified, $field->name, $field->indexName('uq'));
            } catch (QueryException $e) {
                if ($this->errors->classify($e) === DatabaseErrorType::UniqueViolation) {
                    $this->fail(__('tables.errors.not_unique', ['name' => $field->name]));
                }

                throw $e;
            }
        }
    }

    private function dropConstraints(CustomTable $table, CustomField $field): void
    {
        $connection = $this->namespace->connection();
        $dialect = $this->namespace->dialect();

        if ($field->type === FieldType::Relation) {
            $this->schema()->table($this->namespace->qualify($table->name), fn (Blueprint $b) => $b->dropForeign($field->indexName('fk')));
        }

        $dialect->dropIndex($connection, $this->namespace->name(), $table->name, $field->indexName('idx'));
        $dialect->dropIndex($connection, $this->namespace->name(), $table->name, $field->indexName('uq'));
    }

    /**
     * Indizierte Textfelder (eindeutig, sortierbar) dürfen die Indexgrenze der Datenbank nicht
     * überschreiten (MySQL: 768 Zeichen).
     *
     * @param  array<string, mixed>  $attributes  normalisierte Feld-Attribute
     */
    private function assertIndexable(array $attributes): void
    {
        $max = $this->namespace->dialect()->maxIndexedStringLength();

        if ($max !== null && $attributes['type'] === FieldType::String && ($attributes['is_unique'] || $attributes['is_sortable'])
            && (int) $attributes['options']['length'] > $max) {
            $this->fail(__('tables.errors.index_length', ['name' => $attributes['name'], 'max' => $max]));
        }
    }

    /** Doppelte Werte unter den nicht gelöschten Datensätzen (wie der eindeutige Index). */
    private function hasActiveDuplicates(CustomTable $table, string $column): bool
    {
        return $this->namespace->connection()->table($this->namespace->qualify($table->name))
            ->whereNull('deleted_at')
            ->whereNotNull($column)
            ->select($column)
            ->groupBy($column)
            ->havingRaw('count(*) > 1')
            ->exists();
    }

    // --- Angleichen nach einem Fehler (nur ohne transaktionale DDL, z. B. MySQL) -----------

    /**
     * @return array{exists: bool, columns: array<string, string>}
     */
    private function snapshot(string $tableName): array
    {
        $qualified = $this->namespace->qualify($tableName);

        if (! $this->schema()->hasTable($qualified)) {
            return ['exists' => false, 'columns' => []];
        }

        return ['exists' => true, 'columns' => array_column($this->schema()->getColumns($qualified), 'type', 'name')];
    }

    /**
     * Die Metadaten sind zurückgerollt; die Tabelle wird an sie angeglichen: neue Tabelle
     * entfernen, Umbenennung umkehren, neue Spalten entfernen, Typ einer leeren Spalte
     * zurücksetzen, Indizes und Fremdschlüssel nach den Metadaten. Was nicht verlustfrei geht,
     * bleibt bestehen und meldet app-data:verify. Fehler beim Angleichen verdecken nie den
     * ursprünglichen Fehler.
     *
     * @param  array{exists: bool, columns: array<string, string>}  $before
     */
    private function restore(string $tableName, array $before): void
    {
        try {
            $qualified = $this->namespace->qualify($tableName);
            $schema = $this->schema();

            if (! $schema->hasTable($qualified)) {
                return;
            }

            if (! $before['exists']) {
                $schema->drop($qualified);

                return;
            }

            $table = CustomTable::query()->with('fields')->where('name', $tableName)->first();

            if (! $table instanceof CustomTable) {
                return;
            }

            $this->dropUnexpectedConstraints($table);

            $now = array_column($schema->getColumns($qualified), 'type', 'name');
            $added = array_values(array_diff(array_keys($now), array_keys($before['columns'])));
            $removed = array_values(array_diff(array_keys($before['columns']), array_keys($now)));

            if (count($added) === 1 && count($removed) === 1) {
                $schema->table($qualified, fn (Blueprint $b) => $b->renameColumn($added[0], $removed[0]));
            } elseif ($added !== []) {
                $schema->table($qualified, fn (Blueprint $b) => $b->dropColumn($added));
            }

            foreach ($table->fields as $field) {
                $type = $before['columns'][$field->name] ?? null;

                if ($type !== null && $schema->hasColumn($qualified, $field->name)
                    && array_column($schema->getColumns($qualified), 'type', 'name')[$field->name] !== $type
                    && $this->columnIsEmpty($table, $field->name)) {
                    $this->recreateColumn($qualified, $field->name, $field->type, $field->optionsArray());
                }
            }

            $existing = $this->constraintNames($table);

            foreach ($table->fields as $field) {
                $this->createConstraints($table, $field, $existing);
            }
        } catch (Throwable $restoreError) {
            report($restoreError);
        }
    }

    /** Indizes und Fremdschlüssel cf_<id>_…, die nach den Metadaten nicht (mehr) bestehen. */
    private function dropUnexpectedConstraints(CustomTable $table): void
    {
        $expected = [];

        foreach ($table->fields as $field) {
            $expected[] = $field->type === FieldType::Relation ? $field->indexName('fk') : null;
            $expected[] = $field->needsIndex() ? $field->indexName('idx') : null;
            $expected[] = $field->is_unique ? $field->indexName('uq') : null;
        }

        $qualified = $this->namespace->qualify($table->name);
        $unexpected = static fn (string $name): bool => preg_match('/^cf_\d+_(fk|idx|uq)$/', $name) === 1 && ! in_array($name, $expected, true);

        foreach (array_filter(array_column($this->schema()->getForeignKeys($qualified), 'name'), $unexpected) as $name) {
            $this->schema()->table($qualified, fn (Blueprint $b) => $b->dropForeign($name));
        }

        foreach (array_filter(array_column($this->schema()->getIndexes($qualified), 'name'), $unexpected) as $name) {
            $this->namespace->dialect()->dropIndex($this->namespace->connection(), $this->namespace->name(), $table->name, $name);
        }
    }

    /**
     * @return list<string>
     */
    private function constraintNames(CustomTable $table): array
    {
        $qualified = $this->namespace->qualify($table->name);

        return [
            ...array_column($this->schema()->getForeignKeys($qualified), 'name'),
            ...array_column($this->schema()->getIndexes($qualified), 'name'),
        ];
    }

    private function assertColumnNameAvailable(CustomTable $table, string $name, ?CustomField $except = null): void
    {
        $taken = $table->fields()->where('name', $name)->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))->exists();

        if ($taken || $this->schema()->hasColumn($this->namespace->qualify($table->name), $name)) {
            $this->fail(__('tables.errors.column_exists', ['table' => $table->name, 'column' => $name]));
        }
    }

    private function assertDestructionConfirmed(bool $isActive, string $name, string $confirmation, string $what): void
    {
        if ($isActive) {
            $this->fail(__('tables.errors.deactivate_first', ['what' => $what, 'name' => $name]));
        }

        if (! hash_equals($name, $confirmation)) {
            $this->fail(__('tables.errors.confirmation', ['name' => $name]));
        }
    }

    /**
     * Schnittstelle einer Tabelle (B-107, Etappe 5): für andere Apps freigeben und die freigegebenen Felder wählen. Was
     * andere Apps nutzen, lässt sich nicht zurücknehmen; nimmt man unbenutzte Freigaben zurück, steigt die Version der
     * Schnittstelle der App (unverträgliche Änderung).
     *
     * @param  list<string>  $fields  technische Namen der freigegebenen Felder
     */
    public function updateInterface(CustomTable $table, bool $shared, array $fields): void
    {
        $this->assertDesigner($table);
        $table->loadMissing('fields');
        $fields = $shared ? array_values(array_intersect($table->fields->pluck('name')->all(), $fields)) : [];
        $before = $table->is_shared ? $table->fields->where('is_shared', true)->pluck('name')->all() : [];
        $removed = array_values(array_diff($before, $fields));

        if ($table->is_shared && ! $shared) {
            $this->assertNotUsedByOtherApps($table);
        }

        foreach ($removed as $field) {
            $this->assertNotUsedByOtherApps($table, $field);
        }

        DB::transaction(function () use ($table, $shared, $fields, $removed): void {
            $table->fill(['is_shared' => $shared])->save();

            foreach ($table->fields as $field) {
                $field->fill(['is_shared' => $shared && in_array($field->name, $fields, true)])->save();
            }

            if ($removed !== [] && $table->app !== null) {
                $table->app->increment('interface_version');
            }
        });
    }

    /** Nutzt eine andere App die Tabelle bzw. das Feld über ihre Schnittstelle? Dann ist es Vertrag (B-107, Etappe 5). */
    private function assertNotUsedByOtherApps(CustomTable $table, ?string $field = null): void
    {
        if (! $table->is_shared || ($usages = app(AppInterfaces::class)->usages($table, $field)) === []) {
            return;
        }

        $this->fail(__($field === null ? 'apps.errors.interface_table_used' : 'apps.errors.interface_field_used', [
            'name' => $field ?? $table->name,
            'usages' => implode(', ', $usages),
        ]));
    }

    /** Tabelle bzw. Feld wird von einem installierten Plugin gebraucht (SaftPlugin::requiredTables()). */
    private function assertNotRequiredByPlugin(string $table, ?string $field = null): void
    {
        $dependencies = app(PluginDependencies::class);

        if (($plugins = $dependencies->usedBy($table, $field)) !== []) {
            $this->fail(__($field === null ? 'tables.errors.table_required_by_plugin' : 'tables.errors.required_by_plugin', [
                'name' => $field ?? $table,
                'plugins' => $dependencies->names($plugins),
            ]));
        }
    }

    private function fail(string $message): never
    {
        throw new BusinessRuleException($message, 'dynamic.schema');
    }
}
