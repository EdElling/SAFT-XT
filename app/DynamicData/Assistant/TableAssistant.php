<?php

declare(strict_types=1);

namespace App\DynamicData\Assistant;

use App\Ai\AiConfig;
use App\Ai\AiConversation;
use App\Ai\AiUsage;
use App\DynamicData\DataMenu;
use App\DynamicData\Rules\TableRule;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Filament\Support\TableNameRule;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use App\Services\DynamicSchemaService;
use App\Settings\AppSettings;
use App\Support\IconCatalog;
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
        Choose a fitting icon for the table from "icons" in the data model (e.g. "users" for members, "truck" for
        deliveries); use exactly one of these names, or null.

        Fields have a technical name (same rules as table names), a label in all languages and a type:
        string (short text; length 1-10000, default 255), text (long text), integer, decimal (precision 1-38, scale ≤
        precision), boolean, date, datetime, select (fixed choices: value = short technical value, label in all
        languages), relation (link to an existing table of the table designer: relation_table = its technical name,
        relation_display = 1-3 non-relation fields of that table shown as the name, e.g. first and last name), file
        (attachments such as photos, contracts or documents: file_kinds = images, pdf, office, text or any; max_mb =
        maximum size per file, 1-200, usually 10; max_files = 1-50, 1 for a single photo or contract).
        Flags: required, unique (not for text, boolean and file), searchable (string, text, select), sortable (an index
        for fields often filtered or sorted; not for text and file). Use 0 for length/precision/scale/max_mb/max_files
        and "" for relation_table when they do not apply, empty lists for choices/relation_display/file_kinds when they do
        not apply.

        Field rules (only when the user asks for limits or formats) go into "rules" as short text, separated by ";":
        min=<number> and max=<number> (integer, decimal), min_length=<n> and max_length=<n> (string, text), email and url
        (string only), e.g. "min=1; max=3" for "quantity at most 3". Empty "" for no rules. Rules that compare two fields
        or a field with a fixed value for the whole table (e.g. "end not before start") are table rules: they can only be
        added to an existing table (Adjust with AI) – when creating, leave them out.

        Create relations only when the description asks for them or clearly implies them (e.g. "belongs to a club" and a
        table for clubs exists). Use only existing tables and fields from the data model. Keep tables focused: the
        fields the description names plus obvious ones; no speculative extras.

        When the request is ambiguous, set kind to "question" and ask one short question in the user's language; fill
        the remaining properties with empty values. Each request includes the current state (form or table): change it
        when the user asks for a change, otherwise start over.
        TXT;

    /** Nur für neue Tabellen (B-107): Jede Tabelle gehört zu einer App. */
    private const string APP_INSTRUCTIONS = <<<'TXT'
        Every table belongs to exactly one app (business application, see "apps" in the data model). Set app to the id of
        the app the user names in the request; otherwise to the app of the current form state (form.app). If neither
        names an app and there is more than one app, set kind to "question" and ask in which app the table belongs,
        listing the app names. The menu group and the tables of relations must belong to the chosen app (see "app" of
        each menu group and table); groups and tables of other apps cannot be used. Do not put the app prefix into the
        technical name – it is added automatically.
        TXT;

    private const string CHANGE_INSTRUCTIONS = <<<'TXT'
        Here you change an existing table. In "add" list new fields; in "change" list existing fields (by technical name)
        with only the properties to change, all others null: label (all languages), required, unique, searchable,
        sortable, add_choices (new choices of a select field) and relation_display (fields shown for a relation). Field
        types, technical names and deleting fields cannot be changed – if the user asks for that, set kind to
        "question" and explain briefly that this must be done by hand in the table designer. In "change", rules changes
        field rules of an existing field ("max=3"; "max=" removes the maximum; "" = unchanged). In "add_rules" list new
        table rules as "<field> <operator> <field or value>" with technical field names and the operators =, <>, <, <=,
        >, >= (only = and <> for text, choice and yes/no fields), e.g. "ende >= beginn" or "menge > 0"; dates as
        YYYY-MM-DD. Fields used must be of the same kind (numbers, dates, text). The table's existing rules are listed in
        table.rules – do not repeat them. A new rule with the same two fields replaces the existing one (e.g. "lieferdatum
        > bestelldatum" replaces "lieferdatum >= bestelldatum"). Other changes and removing rules are done by hand –
        explain that briefly. If a requested rule contradicts another rule of the table (existing or new) or can never be
        fulfilled (e.g. "lieferdatum > bestelldatum" together with "lieferdatum < bestelldatum", or "menge > 5" with a
        maximum of 3), do not propose it: set kind to "question" and explain in one or two sentences why it makes no
        sense. A rule on the same two fields replaces the existing one; if the new comparison is the opposite of the
        existing one (e.g. "<" instead of ">"), ask first whether the existing rule should really be replaced. Set icon (the table's
        icon, from "icons" in the data model) only when the user asks for a (different) icon, otherwise "".
        TXT;

    /** Vergleich mit vertauschten Seiten: a < b ⇔ b > a. */
    private const array FLIPPED = ['=' => '=', '<>' => '<>', '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<='];

    /** App, deren Tabellen der Assistent sieht (B-107); null = alle. */
    private ?int $appId = null;

    /** Neue Tabelle: Datenmodell aller Apps, je Tabelle und Gruppe mit ihrer App (B-107). */
    private bool $allApps = false;

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
        // Datenmodell aller Apps (B-107): Die App kann im Text genannt sein; Gruppe und Verknüpfungen müssen zu ihr passen.
        $this->allApps = true;
        $formApp = filled($form['app_id'] ?? null) ? (int) $form['app_id'] : null;

        $this->assertCanAsk($user, $text);
        $request = $this->request($text, ['form' => $this->formForModel($form)]);

        return $this->run($user, $text, [self::INSTRUCTIONS, self::APP_INSTRUCTIONS], $history, $request, TableProposalSchema::table(), function (array $proposal) use ($formApp): array {
            return $this->tableProblems($proposal, $formApp);
        }, fn (array $proposal): array => [$this->toForm($proposal, $formApp), []]);
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
     * @param  array{icon?: string|null, add: list<array<string, mixed>>, change: list<array{name: string, data: array<string, mixed>}>}  $changes
     * @return list<array{ok: bool, text: string}>
     */
    public function apply(CustomTable $table, array $changes): array
    {
        $service = app(DynamicSchemaService::class);
        $results = [];

        if (filled($changes['icon'] ?? null)) {
            try {
                $service->updateTable($table, ['icon' => (string) $changes['icon']]);
                $results[] = ['ok' => true, 'text' => __('tables_assistant.applied.icon')];
            } catch (BusinessRuleException $e) {
                $results[] = ['ok' => false, 'text' => $e->getMessage()];
            }
        }

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

        // Neue Tabellenregeln zu den vorhandenen (nach den Feldern, damit neue Felder schon da sind).
        if (($rules = $changes['rules'] ?? []) !== []) {
            try {
                $fresh = ($table->fresh() ?? $table)->load('fields');
                $service->updateTable($fresh, ['validation_rules' => $this->planTableRules($fresh, $rules)['rows']]);
                $results[] = ['ok' => true, 'text' => trans_choice('tables_assistant.applied.rules', count($rules), ['count' => count($rules)])];
            } catch (BusinessRuleException $e) {
                $results[] = ['ok' => false, 'text' => $e->getMessage()];
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
        if ($this->allApps) {
            return $this->dataModelOfAllApps();
        }

        return [
            'tables' => CustomTable::query()->designer()->when($this->appId !== null, fn ($q) => $q->where('app_id', $this->appId))->with('fields')->orderBy('name')->get()->map(fn (CustomTable $t): array => [
                'name' => $t->name,
                'label' => (string) $t->label,
                'fields' => $t->fields->where('is_active', true)->map(fn (CustomField $f): array => ['name' => $f->name, 'label' => (string) $f->label, 'type' => $f->type->value])->values()->all(),
            ])->all(),
            'menu_groups' => collect(app(DataMenu::class)->groupOptions($this->appId))->map(fn (string $label, string $id): array => ['id' => $id, 'label' => trim($label)])->values()->all(),
            'icons' => IconCatalog::assistantNames(),
        ];
    }

    /**
     * Neue Tabelle (B-107): Apps, Tabellen und Menügruppen aller Apps, je mit ihrer App; ohne Systemgruppen.
     *
     * @return array<string, mixed>
     */
    private function dataModelOfAllApps(): array
    {
        $apps = App::query()->orderBy('sort_order')->orderBy('id')->get();
        $groups = [];

        foreach ($apps as $app) {
            foreach (app(DataMenu::class)->groupOptions($app->id) as $id => $label) {
                $groups[] = ['id' => (string) $id, 'label' => trim($label), 'app' => $app->id];
            }
        }

        return [
            'apps' => $apps->map(fn (App $app): array => ['id' => $app->id, 'name' => (string) $app->name, 'prefix' => $app->tablePrefix()])->all(),
            'tables' => CustomTable::query()->designer()->whereNotNull('app_id')->with('fields')->orderBy('name')->get()->map(fn (CustomTable $t): array => [
                'name' => $t->name,
                'label' => (string) $t->label,
                'app' => $t->app_id,
                'fields' => $t->fields->where('is_active', true)->map(fn (CustomField $f): array => ['name' => $f->name, 'label' => (string) $f->label, 'type' => $f->type->value])->values()->all(),
            ])->all(),
            'menu_groups' => $groups,
            'icons' => IconCatalog::assistantNames(),
        ];
    }

    /**
     * App des Vorschlags: genannte, sonst die des Formulars, sonst die einzige; null = offen.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function proposedApp(array $proposal, ?int $formApp): ?int
    {
        $proposed = $proposal['app'] ?? null;

        if (is_numeric($proposed) && App::query()->whereKey((int) $proposed)->exists()) {
            return (int) $proposed;
        }

        return $formApp ?? App::onlyId();
    }

    /** @param  array<string, mixed>  $form */
    private function formForModel(array $form): array
    {
        return [
            'app' => filled($form['app_id'] ?? null) ? (int) $form['app_id'] : null,
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
            // Vorhandene Tabellenregeln (B-110), damit der Assistent sie nicht doppelt anlegt.
            'rules' => array_map(static fn (array $row): string => $row['left'].' '.$row['operator'].' '
                .($row['right_kind'] === TableRuleNormalizer::KIND_FIELD ? $row['right_field'] : (string) ($row['right_value'] ?? $row['right_date'])), TableRuleNormalizer::toRows($table)),
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
    private function tableProblems(array $proposal, ?int $formApp = null): array
    {
        $errors = [];
        $appId = $this->proposedApp($proposal, $formApp);

        if (is_numeric($proposal['app'] ?? null) && ! App::query()->whereKey((int) $proposal['app'])->exists()) {
            $errors[] = __('tables_assistant.errors.app', ['id' => (string) $proposal['app']]);
        } elseif ($appId === null) {
            $errors[] = __('tables_assistant.errors.app_missing');
        }
        $name = (string) ($proposal['name'] ?? '');

        $fail = function (string $message) use (&$errors): void {
            $errors[] = $message;
        };
        FieldForm::identifierRule(table: true)()('name', $name, $fail);

        if (($owner = TableNameRule::takenBy($name)) !== null) {
            $errors[] = __('tables_assistant.errors.name_taken', ['name' => $name, 'owner' => $owner]);
        }

        if (filled($proposal['icon'] ?? null) && IconCatalog::fromAssistant($proposal['icon']) === null) {
            $errors[] = __('tables_assistant.errors.icon', ['icon' => (string) $proposal['icon']]);
        }

        // Nur Gruppen der App (keine Systemgruppen wie die Administration).
        if (filled($proposal['menu_group'] ?? null) && ($appId === null || ! array_key_exists((string) $proposal['menu_group'], app(DataMenu::class)->groupOptions($appId)))) {
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
            $errors = [...$errors, ...$this->ruleTextProblems($field)];
            $errors = [...$errors, ...$this->fieldProblems(TableProposalSchema::toFormField($field), null)];

            // Verknüpfungen nur innerhalb der App (B-107).
            $target = ($field['type'] ?? null) === 'relation' ? CustomTable::query()->where('name', (string) ($field['relation_table'] ?? ''))->first() : null;

            if ($target !== null && $appId !== null && $target->app_id !== $appId) {
                $errors[] = __('tables_assistant.errors.relation_app', ['name' => (string) ($field['name'] ?? ''), 'table' => $target->name]);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed> Formularzustand
     */
    private function toForm(array $proposal, ?int $formApp = null): array
    {
        return [
            'app_id' => $this->proposedApp($proposal, $formApp),
            'label' => TableProposalSchema::cleanTranslations($proposal['label'] ?? null),
            'name' => (string) ($proposal['name'] ?? ''),
            'description' => TableProposalSchema::cleanTranslations($proposal['description'] ?? null),
            'icon' => IconCatalog::fromAssistant($proposal['icon'] ?? null),
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

        $icon = $proposal['icon'] ?? null;
        $tableRules = array_values(array_filter(array_map('strval', (array) ($proposal['add_rules'] ?? [])), 'filled'));

        if ($adds === [] && $changes === [] && blank($icon) && $tableRules === []) {
            $errors[] = __('tables_assistant.errors.no_changes');
        }

        if (filled($icon) && IconCatalog::fromAssistant($icon) === null) {
            $errors[] = __('tables_assistant.errors.icon', ['icon' => (string) $icon]);
        }

        foreach ($adds as $add) {
            if (in_array($add['name'] ?? null, $existing, true)) {
                $errors[] = __('tables_assistant.errors.field_exists', ['name' => (string) $add['name']]);
            } else {
                $errors = [...$errors, ...$this->ruleTextProblems($add), ...$this->fieldProblems(TableProposalSchema::toFormField($add), $table)];
            }
        }

        foreach ($changes as $change) {
            $field = $table->fields->firstWhere('name', $change['name'] ?? null);

            if (! $field instanceof CustomField) {
                $errors[] = __('tables_assistant.errors.unknown_field', ['name' => (string) ($change['name'] ?? '')]);

                continue;
            }

            if (($ruleErrors = $this->ruleTextProblems($change)) !== []) {
                $errors = [...$errors, ...$ruleErrors];

                continue;
            }

            try {
                $this->normalizer->normalize([...$this->current($field), ...$this->changeData($field, $change)], $table);
            } catch (BusinessRuleException $e) {
                $errors[] = __('tables_assistant.errors.field', ['name' => $field->name, 'error' => $e->getMessage()]);
            }
        }

        // Neue Tabellenregeln: gegen die Tabelle samt neuer Felder prüfen, zusammen mit den vorhandenen Regeln.
        if ($tableRules !== []) {
            try {
                $plan = $this->planTableRules($this->withAddedFields($table, $adds), $tableRules);

                if ($plan['added'] === [] && $plan['replaced'] === []) {
                    $errors[] = __('tables_assistant.errors.table_rule_exists');
                }
            } catch (BusinessRuleException $e) {
                $errors[] = __('tables_assistant.errors.table_rule', ['error' => $e->getMessage()]);
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
            'icon' => IconCatalog::fromAssistant($proposal['icon'] ?? null),
            'rules' => array_values(array_filter(array_map('strval', (array) ($proposal['add_rules'] ?? [])), 'filled')),
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

        // Nur echte Änderungen: Das Modell wiederholt die Bezeichnung oft unverändert (Pflichtfeld im Schema).
        if (($label = TableProposalSchema::cleanTranslations($change['label'] ?? null)) !== []
            && ($merged = [...$field->getTranslations('label'), ...$label]) != $field->getTranslations('label')) {
            $data['label'] = $merged;
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

        if ($field->type === FieldType::Relation && is_array($change['relation_display'] ?? null) && $change['relation_display'] !== []
            && array_values(array_map('strval', $change['relation_display'])) !== array_values((array) ($field->optionsArray()['display_fields'] ?? []))) {
            $data['options'] = [...$field->optionsArray(), 'display_fields' => array_values(array_map('strval', $change['relation_display']))];
        }

        // Feldregeln (B-110): Änderungen auf die vorhandenen Regeln; nur wenn sich etwas ändert.
        if (filled($change['rules'] ?? null)) {
            try {
                $current = (array) ($field->validation_rules ?? []);
                $merged = RuleText::merge($current, RuleText::fieldRules((string) $change['rules']));

                if ($merged != $current) {
                    $data['validation_rules'] = $merged;
                }
            } catch (\InvalidArgumentException) {
                // meldet ruleTextProblems()
            }
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
     * Feldregeln in Textform prüfen (Schreibweise); Werte prüft danach der FieldDefinitionNormalizer.
     *
     * @param  array<string, mixed>  $field  vorgeschlagenes Feld oder Änderung
     * @return list<string>
     */
    private function ruleTextProblems(array $field): array
    {
        try {
            RuleText::fieldRules((string) ($field['rules'] ?? ''));

            return [];
        } catch (\InvalidArgumentException $e) {
            return [__('tables_assistant.errors.rule', ['name' => (string) ($field['name'] ?? ''), 'rule' => $e->getMessage()])];
        }
    }

    /**
     * Neue Tabellenregeln zu den vorhandenen: Eine Regel mit denselben zwei Feldern ersetzt die vorhandene (nur der
     * Vergleich ändert sich, Meldung und „nur wenn gefüllt“ bleiben); eine gleiche Regel wird nicht doppelt angelegt.
     * Geprüft wird die ganze Liste wie bei der Eingabe von Hand.
     *
     * @param  list<string>  $texts
     * @return array{rows: list<array<string, mixed>>, added: list<TableRule>, replaced: list<array{0: TableRule, 1: TableRule}>}
     *
     * @throws BusinessRuleException
     */
    private function planTableRules(CustomTable $table, array $texts): array
    {
        $rows = TableRuleNormalizer::toRows($table);
        $added = [];
        $replaced = [];

        foreach ($texts as $text) {
            try {
                $row = RuleText::tableRow($text, $table);
            } catch (\InvalidArgumentException) {
                throw new BusinessRuleException(__('tables_assistant.errors.table_rule_text', ['rule' => $text]));
            }

            // Gleiches Feldpaar in beiden Schreibrichtungen: „bestelldatum < lieferdatum“ entspricht „lieferdatum > bestelldatum“.
            $index = null;

            if ($row['right_kind'] === TableRuleNormalizer::KIND_FIELD) {
                foreach ($rows as $i => $r) {
                    if ($r['right_kind'] !== TableRuleNormalizer::KIND_FIELD) {
                        continue;
                    }

                    if ($r['left'] === $row['left'] && $r['right_field'] === $row['right_field']) {
                        $index = $i;

                        break;
                    }

                    if ($r['left'] === $row['right_field'] && $r['right_field'] === $row['left']) {
                        // In der Schreibrichtung der vorhandenen Regel ausdrücken.
                        $row = [...$row, 'left' => $r['left'], 'right_field' => $r['right_field'], 'operator' => self::FLIPPED[$row['operator']]];
                        $index = $i;

                        break;
                    }
                }
            }

            if ($index === null) {
                if (! in_array($row, $rows, false)) {
                    $rows[] = $row;
                    $added[] = count($rows) - 1;
                }

                continue;
            }

            if ($rows[$index]['operator'] !== $row['operator']) {
                $replaced[] = [$rows[$index], $index];
                $rows[$index]['operator'] = $row['operator'];
            }
        }

        $rules = array_map(TableRule::fromArray(...), app(TableRuleNormalizer::class)->normalize($table, $rows));

        return [
            'rows' => $rows,
            'added' => array_map(static fn (int $i): TableRule => $rules[$i], $added),
            'replaced' => array_map(fn (array $r): array => [TableRule::fromArray(app(TableRuleNormalizer::class)->normalize($table, [$r[0]])[0]), $rules[$r[1]]], $replaced),
        ];
    }

    /**
     * Die Tabelle mit den vorgeschlagenen neuen Feldern (nicht gespeichert), damit neue Tabellenregeln sie verwenden können.
     *
     * @param  list<array<string, mixed>>  $adds  vorgeschlagene Felder (Antwort oder Formularzustand)
     */
    private function withAddedFields(CustomTable $table, array $adds): CustomTable
    {
        $virtual = clone $table;
        $added = array_map(static function (array $add): CustomField {
            $form = isset($add['options']) ? $add : TableProposalSchema::toFormField($add);

            return new CustomField(['name' => (string) $form['name'], 'type' => FieldType::from((string) $form['type']), 'label' => $form['label'] ?? [], 'options' => $form['options'] ?? [], 'is_active' => true]);
        }, $adds);

        return $virtual->setRelation('fields', $table->fields->concat($added)->values());
    }

    /**
     * @param  array{add: list<array<string, mixed>>, change: list<array{name: string, data: array<string, mixed>}>, rules?: list<string>}  $changes
     * @return list<string>
     */
    private function summary(CustomTable $table, array $changes): array
    {
        $lines = [];

        if (filled($changes['icon'] ?? null)) {
            $lines[] = __('tables_assistant.summary.icon', ['icon' => substr((string) $changes['icon'], strlen(IconCatalog::ASSISTANT_PREFIX))]);
        }

        foreach ($changes['add'] as $add) {
            $lines[] = __('tables_assistant.summary.add', [
                'field' => Translations::pick($add['label'] ?? null, (string) $add['name']),
                'type' => FieldType::from((string) $add['type'])->label().($add['type'] === 'relation' ? ' → '.((string) ($add['options']['table'] ?? '')) : ''),
            ]).(($add['validation_rules'] ?? []) !== [] ? ' – '.RuleText::describeField((array) $add['validation_rules']) : '');
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

            if (array_key_exists('validation_rules', $change['data'])) {
                $before = (array) ($field?->validation_rules ?? []);
                $after = (array) $change['data']['validation_rules'];
                $changed = [];

                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                    if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                        $changed[$key] = $after[$key] ?? null;
                    }
                }

                $parts[] = __('tables_assistant.summary.rules', ['rules' => RuleText::describeField($changed)]);
            }

            $lines[] = __('tables_assistant.summary.change', ['field' => (string) ($field?->label ?? $change['name']), 'what' => implode(', ', $parts)]);
        }

        if (($changes['rules'] ?? []) !== []) {
            $virtual = $this->withAddedFields($table, $changes['add']);

            $plan = $this->planTableRules($virtual, $changes['rules']);

            foreach ($plan['replaced'] as [$old, $new]) {
                $lines[] = __('tables_assistant.summary.table_rule_changed', ['old' => TableRuleNormalizer::describe($old, $virtual), 'new' => TableRuleNormalizer::describe($new, $virtual)]);
            }

            foreach ($plan['added'] as $rule) {
                $lines[] = __('tables_assistant.summary.table_rule', ['rule' => TableRuleNormalizer::describe($rule, $virtual)]);
            }
        }

        return $lines;
    }
}
