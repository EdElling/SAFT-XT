<?php

declare(strict_types=1);

namespace App\DynamicData\Assistant;

use App\Ai\AiConfig;
use App\Ai\AiConversation;
use App\Ai\AiUsage;
use App\DynamicData\DataMenu;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Filament\Support\TableNameRule;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use App\Services\DynamicSchemaService;
use App\Settings\AppSettings;
use App\Support\Translations;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KI-Assistent im Table-Designer: neue Tabelle aus einer Beschreibung (Formular wird vorausgefüllt) und Änderungen an
 * einer bestehenden Tabelle (Felder ergänzen, Bezeichnungen und Eigenschaften ändern, Auswahloptionen ergänzen,
 * Verknüpfungen zu anderen Tabellen des Table-Designers). Nie Typ ändern, umbenennen oder löschen.
 *
 * Übertragen werden nur Beschreibung, Formular- bzw. Tabellendefinition, die Struktur der übrigen Tabellen des
 * Table-Designers und die Menügruppen – nie Datensätze.
 */
final class TableAssistant
{
    private const int HISTORY_TURNS = 6;

    private const string INSTRUCTIONS = <<<'TXT'
        You design database tables for SAFT, a database application. Respond only with JSON that matches the given schema.

        A table has a technical name (lower case letters, digits and underscores, starting with a letter, at most 63
        characters, singular or plural noun in the language of the user, e.g. "mitglieder"), a label and an optional
        description, each in all languages of the schema (de, en, fr, it – translate them), and an optional menu group
        (the id of an existing group, or null). Every table automatically has an id and timestamps – never add them.

        Fields have a technical name (same rules as table names), a label in all languages and a type:
        string (short text; length 1-10000, default 255), text (long text), integer, decimal (precision 1-38, scale ≤
        precision), boolean, date, datetime, select (fixed choices: value = short technical value, label in all
        languages), relation (link to an existing table of the table designer: relation_table = its technical name,
        relation_display = 1-3 non-relation fields of that table shown as the name, e.g. first and last name), file
        (attachments such as photos, contracts or documents: file_kinds = images, pdf, office, text or any; max_mb =
        maximum size per file, 1-200, usually 10; max_files = 1-50, 1 for a single photo or contract).
        Flags: required, unique (not for text, boolean and file), searchable (string, text, select), sortable (an index
        for fields often filtered or sorted; not for text and file). Use null for length/precision/scale/max_mb/max_files
        when they do not apply, empty lists for choices/relation_display/file_kinds when they do not apply.

        Create relations only when the description asks for them or clearly implies them (e.g. "belongs to a club" and a
        table for clubs exists). Use only existing tables and fields from the data model. Keep tables focused: the
        fields the description names plus obvious ones; no speculative extras.

        When the request is ambiguous, set kind to "question" and ask one short question in the user's language; fill
        the remaining properties with empty values. Each request includes the current state (form or table): change it
        when the user asks for a change, otherwise start over.
        TXT;

    private const string CHANGE_INSTRUCTIONS = <<<'TXT'
        Here you change an existing table. In "add" list new fields; in "change" list existing fields (by technical name)
        with only the properties to change, all others null: label (all languages), required, unique, searchable,
        sortable, add_choices (new choices of a select field) and relation_display (fields shown for a relation). Field
        types, technical names and deleting fields cannot be changed – if the user asks for that, set kind to
        "question" and explain briefly that this must be done by hand in the table designer.
        TXT;

    /** App, deren Tabellen der Assistent sieht (B-107); null = alle. */
    private ?int $appId = null;

    public function __construct(
        private readonly AssistantClient $client,
        private readonly FieldDefinitionNormalizer $normalizer,
    ) {}

    public static function availableFor(?User $user): bool
    {
        return app(AiConfig::class)->configured() && app(AppSettings::class)->ai_assistant_enabled && ($user?->can('custom_tables.assistant') ?? false);
    }

    /**
     * Neue Tabelle: Vorschlag als Formularzustand des Table-Designers.
     *
     * @param  array<string, mixed>  $form  aktueller Formularzustand
     * @param  list<array{role: string, content: string}>  $history
     */
    public function proposeTable(User $user, string $text, array $form, array $history = []): TableAssistantResult
    {
        // Datenmodell nur der gewählten App (B-107): Verknüpfungen sind nur innerhalb einer App möglich.
        $this->appId = filled($form['app_id'] ?? null) ? (int) $form['app_id'] : null;

        $this->assertCanAsk($user, $text);
        $request = $this->request($text, ['form' => $this->formForModel($form)]);

        return $this->run($user, $text, [self::INSTRUCTIONS], $history, $request, TableProposalSchema::table(), function (array $proposal): array {
            return $this->tableProblems($proposal);
        }, fn (array $proposal): array => [$this->toForm($proposal), []]);
    }

    /**
     * Änderungen an einer bestehenden Tabelle: Vorschlag mit Vorschau in Worten; ausgeführt erst mit apply().
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public function proposeChanges(User $user, CustomTable $table, string $text, array $history = []): TableAssistantResult
    {
        $this->appId = $table->app_id;

        $this->assertCanAsk($user, $text);
        $table->loadMissing('fields');
        $request = $this->request($text, ['table' => $this->tableForModel($table)]);

        return $this->run($user, $text, [self::INSTRUCTIONS, self::CHANGE_INSTRUCTIONS], $history, $request, TableProposalSchema::changes(), function (array $proposal) use ($table): array {
            return $this->changeProblems($table, $proposal);
        }, function (array $proposal) use ($table): array {
            $changes = $this->toChanges($table, $proposal);

            return [$changes, $this->summary($table, $changes)];
        });
    }

    /**
     * Vorgeschlagene Änderungen ausführen, einzeln über den Table-Designer (Änderungsprotokoll wie von Hand).
     *
     * @param  array{add: list<array<string, mixed>>, change: list<array{name: string, data: array<string, mixed>}>}  $changes
     * @return list<array{ok: bool, text: string}>
     */
    public function apply(CustomTable $table, array $changes): array
    {
        $service = app(DynamicSchemaService::class);
        $results = [];

        foreach ($changes['add'] ?? [] as $field) {
            try {
                $service->addField($table->fresh() ?? $table, FieldForm::toServiceData($field));
                $results[] = ['ok' => true, 'text' => __('tables_assistant.applied.added', ['field' => Translations::pick($field['label'] ?? null, (string) $field['name'])])];
            } catch (BusinessRuleException $e) {
                $results[] = ['ok' => false, 'text' => (string) $field['name'].': '.$e->getMessage()];
            }
        }

        foreach ($changes['change'] ?? [] as $change) {
            $field = $table->fields()->where('name', $change['name'])->first();

            if (! $field instanceof CustomField) {
                continue;
            }

            try {
                $service->updateField($field, $change['data']);
                $results[] = ['ok' => true, 'text' => __('tables_assistant.applied.changed', ['field' => (string) $field->label])];
            } catch (BusinessRuleException $e) {
                $results[] = ['ok' => false, 'text' => (string) $field->label.': '.$e->getMessage()];
            }
        }

        return $results;
    }

    // --- Ablauf ------------------------------------------------------------------------

    private function assertCanAsk(User $user, string $text): void
    {
        if (! self::availableFor($user)) {
            throw new BusinessRuleException(__('assistant.errors.not_available'));
        }

        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 2000) {
            throw new BusinessRuleException(__('assistant.errors.prompt_length', ['max' => 2000]));
        }

        if (($limit = AiUsage::limitMessage($user)) !== null) {
            throw new BusinessRuleException($limit);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function request(string $text, array $state): array
    {
        return [
            'role' => 'user',
            'content' => 'Language of the user: '.app()->getLocale().".\n"
                .'Current state: '.json_encode($state, JSON_UNESCAPED_UNICODE)."\n\n"
                .'Request: '.trim($text),
        ];
    }

    /**
     * @param  list<string>  $instructions
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{role: string, content: string}  $request
     * @param  array<string, mixed>  $schema
     * @param  callable(array<string, mixed>): list<string>  $check
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>, 1: list<string>}  $build
     */
    private function run(User $user, string $text, array $instructions, array $history, array $request, array $schema, callable $check, callable $build): TableAssistantResult
    {
        $system = [
            ['type' => 'text', 'text' => implode("\n\n", $instructions)],
            ['type' => 'text', 'text' => "Data model:\n".json_encode($this->dataModel(), JSON_UNESCAPED_UNICODE), 'cache' => true],
        ];
        $conversation = new AiConversation($this->client);

        try {
            $answer = $conversation->ask($system, [...array_slice($history, -2 * self::HISTORY_TURNS), $request], $schema, $check);
        } catch (Throwable $e) {
            Log::warning('Tabellen-Assistent fehlgeschlagen', ['error' => $e->getMessage()]);
            AiUsage::log($user, 'tables', $text, 'error', $conversation->usage, $conversation->model(), $e->getMessage());

            throw new BusinessRuleException(__('assistant.errors.failed'), previous: $e);
        }

        $result = match ($answer['status']) {
            AiConversation::OK => new TableAssistantResult(TableAssistantResult::PROPOSAL, ...array_combine(['proposal', 'summary'], $build($answer['proposal'])), history: $answer['history']),
            AiConversation::QUESTION => new TableAssistantResult(TableAssistantResult::QUESTION, message: $answer['question'], history: $answer['history']),
            AiConversation::REFUSED => new TableAssistantResult(TableAssistantResult::ERROR, message: __('assistant.errors.refused')),
            default => new TableAssistantResult(TableAssistantResult::ERROR, message: __('tables_assistant.errors.invalid', ['errors' => implode('; ', $answer['errors'])])),
        };

        AiUsage::log($user, 'tables', $text, $result->status, $conversation->usage, $conversation->model(), $result->status === TableAssistantResult::ERROR ? $result->message : null);

        return $result;
    }

    // --- Datenmodell und Zustand für das Modell ----------------------------------------

    /** @return array<string, mixed> Tabellen des Table-Designers (nur Struktur) und Menügruppen */
    private function dataModel(): array
    {
        return [
            'tables' => CustomTable::query()->designer()->when($this->appId !== null, fn ($q) => $q->where('app_id', $this->appId))->with('fields')->orderBy('name')->get()->map(fn (CustomTable $t): array => [
                'name' => $t->name,
                'label' => (string) $t->label,
                'fields' => $t->fields->where('is_active', true)->map(fn (CustomField $f): array => ['name' => $f->name, 'label' => (string) $f->label, 'type' => $f->type->value])->values()->all(),
            ])->all(),
            'menu_groups' => collect(app(DataMenu::class)->groupOptions($this->appId))->map(fn (string $label, string $id): array => ['id' => $id, 'label' => trim($label)])->values()->all(),
        ];
    }

    /** @param  array<string, mixed>  $form */
    private function formForModel(array $form): array
    {
        return [
            'name' => $form['name'] ?? null,
            'label' => $form['label'] ?? null,
            'description' => $form['description'] ?? null,
            'menu_group' => $form['menu_group'] ?? null,
            'fields' => array_values(array_map(fn (array $f): array => [
                'name' => $f['name'] ?? null,
                'label' => $f['label'] ?? null,
                'type' => $f['type'] ?? null,
                'options' => $f['options'] ?? [],
                'required' => (bool) ($f['is_required'] ?? false),
                'unique' => (bool) ($f['is_unique'] ?? false),
                'searchable' => (bool) ($f['is_searchable'] ?? false),
                'sortable' => (bool) ($f['is_sortable'] ?? false),
            ], array_filter((array) ($form['fields'] ?? []), 'is_array'))),
        ];
    }

    /** @return array<string, mixed> */
    private function tableForModel(CustomTable $table): array
    {
        return [
            'name' => $table->name,
            'label' => $table->getTranslations('label'),
            'fields' => $table->fields->where('is_active', true)->map(fn (CustomField $f): array => [
                'name' => $f->name,
                'label' => $f->getTranslations('label'),
                'type' => $f->type->value,
                'options' => $f->optionsArray(),
                'required' => $f->is_required,
                'unique' => $f->is_unique,
                'searchable' => $f->is_searchable,
                'sortable' => $f->is_sortable,
            ])->values()->all(),
        ];
    }

    // --- Neue Tabelle -------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $proposal
     * @return list<string>
     */
    private function tableProblems(array $proposal): array
    {
        $errors = [];
        $name = (string) ($proposal['name'] ?? '');

        $fail = function (string $message) use (&$errors): void {
            $errors[] = $message;
        };
        FieldForm::identifierRule(table: true)()('name', $name, $fail);

        if (($owner = TableNameRule::takenBy($name)) !== null) {
            $errors[] = __('tables_assistant.errors.name_taken', ['name' => $name, 'owner' => $owner]);
        }

        if (filled($proposal['menu_group'] ?? null) && ! array_key_exists((string) $proposal['menu_group'], app(DataMenu::class)->groupOptions())) {
            $errors[] = __('tables_assistant.errors.menu_group', ['id' => (string) $proposal['menu_group']]);
        }

        $fields = array_values(array_filter((array) ($proposal['fields'] ?? []), 'is_array'));

        if ($fields === []) {
            $errors[] = __('tables_assistant.errors.no_fields');
        }

        $names = array_map(fn (array $f): string => (string) ($f['name'] ?? ''), $fields);

        foreach (array_unique(array_diff_assoc($names, array_unique($names))) as $duplicate) {
            $errors[] = __('tables_assistant.errors.duplicate_field', ['name' => $duplicate]);
        }

        foreach ($fields as $field) {
            $errors = [...$errors, ...$this->fieldProblems(TableProposalSchema::toFormField($field), null)];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed> Formularzustand
     */
    private function toForm(array $proposal): array
    {
        return [
            'label' => TableProposalSchema::cleanTranslations($proposal['label'] ?? null),
            'name' => (string) ($proposal['name'] ?? ''),
            'description' => TableProposalSchema::cleanTranslations($proposal['description'] ?? null),
            'icon' => null,
            'is_active' => true,
            'export_enabled' => false,
            'menu_group' => filled($proposal['menu_group'] ?? null) ? (string) $proposal['menu_group'] : null,
            'fields' => array_map(TableProposalSchema::toFormField(...), array_values(array_filter((array) ($proposal['fields'] ?? []), 'is_array'))),
        ];
    }

    /**
     * Prüfung eines Felds wie beim Anlegen (Name, Typ, Optionen, Verknüpfung).
     *
     * @param  array<string, mixed>  $field  Formularzustand
     * @return list<string>
     */
    private function fieldProblems(array $field, ?CustomTable $owner): array
    {
        try {
            $this->normalizer->normalize(FieldForm::toServiceData($field), $owner);
        } catch (BusinessRuleException $e) {
            return [__('tables_assistant.errors.field', ['name' => (string) ($field['name'] ?? ''), 'error' => $e->getMessage()])];
        }

        return [];
    }

    // --- Änderungen ----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $proposal
     * @return list<string>
     */
    private function changeProblems(CustomTable $table, array $proposal): array
    {
        $errors = [];
        $existing = $table->fields->pluck('name')->all();
        $adds = array_values(array_filter((array) ($proposal['add'] ?? []), 'is_array'));
        $changes = array_values(array_filter((array) ($proposal['change'] ?? []), 'is_array'));

        if ($adds === [] && $changes === []) {
            $errors[] = __('tables_assistant.errors.no_changes');
        }

        foreach ($adds as $add) {
            if (in_array($add['name'] ?? null, $existing, true)) {
                $errors[] = __('tables_assistant.errors.field_exists', ['name' => (string) $add['name']]);
            } else {
                $errors = [...$errors, ...$this->fieldProblems(TableProposalSchema::toFormField($add), $table)];
            }
        }

        foreach ($changes as $change) {
            $field = $table->fields->firstWhere('name', $change['name'] ?? null);

            if (! $field instanceof CustomField) {
                $errors[] = __('tables_assistant.errors.unknown_field', ['name' => (string) ($change['name'] ?? '')]);

                continue;
            }

            try {
                $this->normalizer->normalize([...$this->current($field), ...$this->changeData($field, $change)], $table);
            } catch (BusinessRuleException $e) {
                $errors[] = __('tables_assistant.errors.field', ['name' => $field->name, 'error' => $e->getMessage()]);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{add: list<array<string, mixed>>, change: list<array{name: string, data: array<string, mixed>}>}
     */
    private function toChanges(CustomTable $table, array $proposal): array
    {
        return [
            'add' => array_map(TableProposalSchema::toFormField(...), array_values(array_filter((array) ($proposal['add'] ?? []), 'is_array'))),
            'change' => array_values(array_filter(array_map(function (array $change) use ($table): ?array {
                $field = $table->fields->firstWhere('name', $change['name'] ?? null);
                $data = $field instanceof CustomField ? $this->changeData($field, $change) : [];

                return $data === [] ? null : ['name' => (string) $change['name'], 'data' => $data];
            }, array_values(array_filter((array) ($proposal['change'] ?? []), 'is_array'))))),
        ];
    }

    /**
     * Nur erlaubte Änderungen: Bezeichnung, Eigenschaften, weitere Auswahloptionen, Anzeigefelder einer Verknüpfung.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function changeData(CustomField $field, array $change): array
    {
        $data = [];

        if (($label = TableProposalSchema::cleanTranslations($change['label'] ?? null)) !== []) {
            $data['label'] = [...$field->getTranslations('label'), ...$label];
        }

        foreach (['required' => 'is_required', 'unique' => 'is_unique', 'searchable' => 'is_searchable', 'sortable' => 'is_sortable'] as $key => $attribute) {
            if (is_bool($change[$key] ?? null) && $change[$key] !== $field->{$attribute}) {
                $data[$attribute] = $change[$key];
            }
        }

        $choices = array_values(array_filter((array) ($change['add_choices'] ?? []), 'is_array'));

        if ($field->type === FieldType::Select && $choices !== []) {
            $current = array_values((array) ($field->optionsArray()['options'] ?? []));
            $known = array_map(fn (array $o): string => (string) ($o['value'] ?? ''), $current);
            $new = array_values(array_filter(array_map(fn (array $c): array => ['value' => (string) ($c['value'] ?? ''), 'label' => TableProposalSchema::cleanTranslations($c['label'] ?? null)], $choices), fn (array $c): bool => ! in_array($c['value'], $known, true)));

            if ($new !== []) {
                $data['options'] = [...$field->optionsArray(), 'options' => [...$current, ...$new]];
            }
        }

        if ($field->type === FieldType::Relation && is_array($change['relation_display'] ?? null) && $change['relation_display'] !== []) {
            $data['options'] = [...$field->optionsArray(), 'display_fields' => array_values(array_map('strval', $change['relation_display']))];
        }

        return $data;
    }

    /** @return array<string, mixed> wie DynamicSchemaService::updateField */
    private function current(CustomField $field): array
    {
        return [
            ...$field->only(['name', 'is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form', 'is_active', 'sort_order']),
            'label' => $field->getTranslations('label'),
            'type' => $field->type,
            'options' => $field->optionsArray(),
            'validation_rules' => (array) ($field->validation_rules ?? []),
        ];
    }

    /**
     * @param  array{add: list<array<string, mixed>>, change: list<array{name: string, data: array<string, mixed>}>}  $changes
     * @return list<string>
     */
    private function summary(CustomTable $table, array $changes): array
    {
        $lines = [];

        foreach ($changes['add'] as $add) {
            $lines[] = __('tables_assistant.summary.add', [
                'field' => Translations::pick($add['label'] ?? null, (string) $add['name']),
                'type' => FieldType::from((string) $add['type'])->label().($add['type'] === 'relation' ? ' → '.((string) ($add['options']['table'] ?? '')) : ''),
            ]);
        }

        foreach ($changes['change'] as $change) {
            $field = $table->fields->firstWhere('name', $change['name']);
            $parts = [];

            foreach (['is_required' => 'required', 'is_unique' => 'unique', 'is_searchable' => 'searchable', 'is_sortable' => 'sortable'] as $attribute => $key) {
                if (array_key_exists($attribute, $change['data'])) {
                    $parts[] = __('tables_assistant.summary.'.($change['data'][$attribute] ? 'on' : 'off'), ['what' => __('tables_assistant.flags.'.$key)]);
                }
            }

            if (isset($change['data']['label'])) {
                $parts[] = __('tables_assistant.summary.label', ['label' => Translations::pick($change['data']['label'], $change['name'])]);
            }

            if (isset($change['data']['options']['options'])) {
                $parts[] = __('tables_assistant.summary.choices', ['count' => count($change['data']['options']['options']) - count((array) ($field?->optionsArray()['options'] ?? []))]);
            }

            if (isset($change['data']['options']['display_fields'])) {
                $parts[] = __('tables_assistant.summary.display', ['fields' => implode(', ', $change['data']['options']['display_fields'])]);
            }

            $lines[] = __('tables_assistant.summary.change', ['field' => (string) ($field?->label ?? $change['name']), 'what' => implode(', ', $parts)]);
        }

        return $lines;
    }
}
