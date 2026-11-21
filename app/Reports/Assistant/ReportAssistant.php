<?php

declare(strict_types=1);

namespace App\Reports\Assistant;

use App\Ai\AiConfig;
use App\Ai\AiConversation;
use App\Ai\AiUsage;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Reports\DefinitionResolver;
use App\Reports\Editor\DesignerState;
use App\Reports\Editor\FieldCatalog;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportExpression;
use App\Settings\AppSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KI-Assistent im Auswertungs-Editor: Beschreibung in eigenen Worten → Zustand des Editors.
 *
 * Das Modell sieht nur das Datenmodell der Person (FieldCatalog::structure) und den Text, nie Datensätze. Es antwortet
 * in einem festen JSON-Schema; SAFT prüft den Vorschlag wie jede Auswertung und gibt Fehler einmal zur Korrektur
 * zurück. Jede Anfrage steht im Änderungsprotokoll (Ereignis „assistant“); je Person gilt ein Tageslimit.
 */
final class ReportAssistant
{
    public const string EVENT = AiUsage::EVENT;

    public const int MAX_PROMPT = 2000;

    /** Gesprächsrunden, die für Nachfragen mitgeschickt werden. */
    private const int HISTORY_TURNS = 6;

    private const string INSTRUCTIONS = <<<'TXT'
        You turn a user's description of a report into a report definition for SAFT, a database application. Respond
        only with JSON that matches the given schema.

        The data model lists the tables this user may evaluate. Each table has an id, a label and fields with id, label
        and type (text, number, date, boolean, relation). A relation field points to another table (`to`); in rows,
        columns or a list it shows the related record by the fields listed in `shows` (e.g. first and last name) and
        keeps records with equal names apart. For "per person", "which customers" and the like, use the relation field
        itself; add further fields of the related table only when the user asks for them. `links` are further
        connections to another table, with negative ids. Select fields list their `choices`; in filters use the choice
        `value`, not the label. Tables marked `interface` belong to another app: they can only be reached through a
        relation or link, never be the base, and the path ends there (only their listed fields, no further steps).

        Field references (`ref`) have the form `<path>:<field id>`. The path lists the relation field ids and link ids,
        separated by `/`, that lead from the base table to the table holding the field; it is empty for fields of the
        base table. Examples: `:31` (field 31 of the base table), `12:40` (field 40 of the table reached through
        relation field 12), `12/-3:7`. At most 3 steps.

        A report consists of:
        - base: the table whose records are listed or counted, e.g. orders for "revenue per month".
        - rows: grouping fields when there are values, otherwise the columns of a plain list. Date fields used for
          grouping take a bucket (day, week, month, quarter, year); otherwise bucket is null.
        - columns: at most one field that spreads values across columns (crosstab).
        - values: count (ref null counts records), count_distinct, sum, avg, min, max. sum and avg only for numbers.
        - filters by field type: text eq, neq, contains, starts_with, in; number eq, neq, gt, gte, lt, lte, between,
          in; date eq, gt, gte, lt, lte, between, today, this_month, last_month, this_year, last_year, last_days
          (value = number of days); boolean is_true, is_false; relation eq, neq, in; any type is_empty, not_empty.
          Values are strings, dates as YYYY-MM-DD; between uses value and value_to; in uses values. A calendar year:
          between YYYY-01-01 and YYYY-12-31.
        - sort: null, or target row/value with the index into rows/values and dir asc/desc. limit: null or the number
          of rows, e.g. 10 for "top 10" (with a matching sort).
        - calculated values and columns: a value may have a `formula` instead of a ref; agg is then sum, avg, min or
          max of the formula computed per record (e.g. sum of quantity × price). In a plain list (no values, no
          columns) a row may have a `formula` instead of a ref: a calculated column. Formula syntax: number fields as
          `[<ref>]` in square brackets, e.g. `[:31] * [12:40]`; numbers; + - * / and parentheses; functions
          round(x; digits), abs(x), min(a; b; …), max(a; b; …), coalesce(x; fallback). An empty field makes the result
          empty (coalesce(x; 0) treats it as 0); division by 0 gives empty. Prefer stored fields: when a number field
          already holds the needed value (e.g. a stored purchase value, total or amount), use it as ref instead of a
          formula. Give calculated items a short `label` in the user's language and `scale` (decimal places: 2 for
          money, 0 for quantities). For all other items set formula, label and scale to null; for a calculated row
          set ref to "" and bucket to null.
        - figures from values (agg "calc"): a value computed from the other values and the grouped number fields of the
          same result row, after summarising, e.g. budget per publication = budget ÷ number of publications per project.
          Use it whenever a ratio or difference of summarised values is asked for. Its `formula` refers to the n-th value
          of the values list as `[#n]` (1-based) and to a grouped number field (in rows, without bucket) by its ref, e.g.
          `[12:40] / [#1]`; same operators and functions as above. Never refer to another "calc" value. Set ref to null.
          A field of a parent table (e.g. the project budget when the base table is publications) repeats in every row
          of its children: group by it (put it in rows) or summarise it with avg or max, never with sum.
        - visual: table; crosstab (needs columns); bar or line (needs rows and values); pie (exactly one row field,
          at least one value, no columns); kpi (exactly one value, no rows, no columns).
        - title: a short name for the report in the user's language.

        Use only tables and fields from the data model; never invent ids. When the request is ambiguous or nothing in
        the data model fits, set kind to "question" and ask one short question in the user's language; then use base
        null, empty lists, sort null, limit null and visual table. Each request includes the current definition of the
        editor (it may be empty or edited by hand): change it when the user asks for a change, otherwise start over.
        TXT;

    public function __construct(
        private readonly AssistantClient $client,
        private readonly FieldCatalog $catalog,
        private readonly DefinitionResolver $resolver,
    ) {}

    public static function configured(): bool
    {
        return app(AiConfig::class)->configured();
    }

    /** Für diese Person sichtbar: konfiguriert, eingeschaltet und Recht „Auswertungen: Assistent nutzen“. */
    public static function availableFor(?User $user): bool
    {
        return self::configured() && app(AppSettings::class)->ai_assistant_enabled && ($user?->can('reports.assistant') ?? false);
    }

    /** Verbleibende KI-Anfragen heute (Limit der Person, gemeinsam für alle KI-Funktionen). */
    public static function remainingToday(User $user): int
    {
        return AiUsage::remainingToday($user);
    }

    public static function usedToday(User $user): int
    {
        return AiUsage::usedToday($user);
    }

    /**
     * @param  array<string, mixed>  $state  aktueller Zustand des Editors
     * @param  list<array{role: string, content: string}>  $history  bisheriger Verlauf
     * @param  bool  $checkPermission  false für den App-Designer (Etappe 7c): dort genügt das Recht apps.design
     */
    public function propose(User $user, string $text, array $state, array $history = [], bool $checkPermission = true): AssistantResult
    {
        $text = trim($text);

        if ($checkPermission ? ! self::availableFor($user) : ! (self::configured() && app(AppSettings::class)->ai_assistant_enabled)) {
            throw new BusinessRuleException(__('assistant.errors.not_available'));
        }

        if ($text === '' || mb_strlen($text) > self::MAX_PROMPT) {
            throw new BusinessRuleException(__('assistant.errors.prompt_length', ['max' => self::MAX_PROMPT]));
        }

        if (($limit = AiUsage::limitMessage($user)) !== null) {
            throw new BusinessRuleException($limit);
        }

        $structure = $this->catalog->structure($user, app(AppSettings::class)->ai_send_choices);

        if ($structure === []) {
            throw new BusinessRuleException(__('assistant.errors.no_tables'));
        }

        $system = [
            ['type' => 'text', 'text' => self::INSTRUCTIONS],
            ['type' => 'text', 'text' => "Data model:\n".json_encode(['tables' => $structure], JSON_UNESCAPED_UNICODE), 'cache' => true],
        ];
        $request = [
            'role' => 'user',
            'content' => 'Today: '.now()->toDateString().'. Language of the user: '.app()->getLocale().".\n"
                .'Current definition: '.json_encode($this->current($state), JSON_UNESCAPED_UNICODE)."\n\n"
                .'Request: '.$text,
        ];
        $messages = [...array_slice($history, -2 * self::HISTORY_TURNS), $request];
        $conversation = new AiConversation($this->client);

        try {
            $answer = $conversation->ask($system, $messages, ProposalSchema::schema(), fn (array $proposal): array => $this->problems($user, ProposalSchema::toState($proposal)));
        } catch (Throwable $e) {
            Log::warning('KI-Assistent fehlgeschlagen', ['error' => $e->getMessage()]);
            AiUsage::log($user, 'reports', $text, 'error', $conversation->usage, $conversation->model(), $e->getMessage());

            throw new BusinessRuleException(__('assistant.errors.failed'), previous: $e);
        }

        $result = match ($answer['status']) {
            AiConversation::OK => new AssistantResult(AssistantResult::REPORT, state: ProposalSchema::toState($answer['proposal']), title: filled($answer['proposal']['title'] ?? null) ? (string) $answer['proposal']['title'] : null, history: $answer['history']),
            AiConversation::QUESTION => new AssistantResult(AssistantResult::QUESTION, message: $answer['question'], history: $answer['history']),
            AiConversation::REFUSED => new AssistantResult(AssistantResult::ERROR, message: __('assistant.errors.refused')),
            default => new AssistantResult(AssistantResult::ERROR, message: __('assistant.errors.invalid', ['errors' => implode('; ', $answer['errors'])])),
        };

        AiUsage::log($user, 'reports', $text, $result->status, $conversation->usage, $conversation->model(), $result->status === AssistantResult::ERROR ? $result->message : null);

        return $result;
    }

    /**
     * Gründe, warum ein Vorschlag nicht passt: unbekannte oder nicht erlaubte Felder, Prüfung der Definition.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function problems(User $user, array $state): array
    {
        $base = $state['base'];

        $bases = array_column(array_filter($this->catalog->structure($user, false), static fn (array $t): bool => ! ($t['interface'] ?? false)), 'id');

        if ($base === null || ! in_array($base, $bases, true)) {
            return [__('assistant.errors.unknown_table', ['id' => (string) $base])];
        }

        if ($state['rows'] === [] && $state['values'] === []) {
            return [__('assistant.errors.empty')];
        }

        $errors = [];
        $formulaRefs = [];

        foreach (['rows', 'values'] as $zone) {
            foreach ($state[$zone] as $item) {
                if (isset($item['formula_error'])) {
                    $errors[] = __('assistant.errors.formula', ['error' => $item['formula_error']]);
                } elseif ($zone === 'values' && is_array($item['calc'] ?? null)) {
                    // Gruppierte Spalten einer Kennzahl aus Werten: Feldreferenz muss erreichbar sein.
                    foreach (ReportExpression::fieldKeys($item['calc']) as $id) {
                        if (str_starts_with($id, '@')) {
                            $formulaRefs[] = substr($id, 1);
                        }
                    }
                } elseif (is_array($item['expr'] ?? null)) {
                    $formulaRefs = [...$formulaRefs, ...ReportExpression::fieldKeys($item['expr'])];

                    if ($zone === 'rows' && ($state['values'] !== [] || $state['columns'] !== [])) {
                        $errors[] = __('reports.ui.formula.list_only');
                    }
                }
            }
        }

        $refs = [
            ...array_filter(array_column($state['rows'], 'ref'), 'filled'), ...array_column($state['columns'], 'ref'),
            ...array_filter(array_column($state['values'], 'ref')), ...array_column($state['filters'], 'ref'), ...$formulaRefs,
        ];

        $unknown = [];

        foreach (array_unique($refs) as $ref) {
            if ($this->catalog->field($base, $user, (string) $ref) === null) {
                $unknown[] = __('assistant.errors.unknown_field', ['ref' => (string) $ref]);
            }
        }

        // Unbekannte Felder zuerst; sonst alle Fehler auf einmal (Formeln und Prüfung der Definition).
        if ($unknown !== []) {
            return array_values(array_unique([...$errors, ...$unknown]));
        }

        [$definition] = DesignerState::fromArray($state)->toDefinition();

        try {
            $this->resolver->resolve($definition);
        } catch (InvalidReportException $e) {
            $errors = [...$errors, ...$e->errors];
        }

        return array_values(array_unique($errors));
    }

    /**
     * Aktueller Zustand für das Modell (nur die Teile, die es selbst liefern kann).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function current(array $state): array
    {
        return [
            'base' => $state['base'] ?? null,
            'rows' => array_map(fn (array $r): array => is_array($r['expr'] ?? null)
                ? ['ref' => '', 'formula' => ProposalSchema::formulaText($r['expr']), 'label' => $r['label'] ?? null, 'scale' => $r['scale'] ?? 2]
                : ['ref' => $r['ref'] ?? null, 'bucket' => $r['bucket'] ?? null], (array) ($state['rows'] ?? [])),
            'columns' => array_map(fn (array $c): array => ['ref' => $c['ref'] ?? null, 'bucket' => $c['bucket'] ?? null], (array) ($state['columns'] ?? [])),
            'values' => array_map(fn (array $v): array => match (true) {
                ($v['agg'] ?? null) === ReportDefinition::CALC => ['agg' => ReportDefinition::CALC, 'ref' => null, 'formula' => ProposalSchema::calcText((array) ($v['calc'] ?? []), (array) ($state['values'] ?? [])), 'label' => $v['label'] ?? null, 'scale' => $v['scale'] ?? 2],
                is_array($v['expr'] ?? null) => ['agg' => $v['agg'] ?? null, 'ref' => null, 'formula' => ProposalSchema::formulaText($v['expr']), 'label' => $v['label'] ?? null, 'scale' => $v['scale'] ?? 2],
                default => ['agg' => $v['agg'] ?? null, 'ref' => $v['ref'] ?? null],
            }, (array) ($state['values'] ?? [])),
            'filters' => array_map(fn (array $f): array => array_intersect_key($f, array_flip(['ref', 'op', 'value', 'value_to', 'values'])), (array) ($state['filters'] ?? [])),
            'sort' => $state['sort'] ?? null,
            'sortDir' => $state['sortDir'] ?? 'asc',
            'limit' => $state['limit'] ?? null,
            'visual' => $state['visual'] ?? 'table',
        ];
    }
}
