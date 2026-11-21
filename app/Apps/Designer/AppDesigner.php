<?php

declare(strict_types=1);

namespace App\Apps\Designer;

use App\Ai\AiConfig;
use App\Ai\AiConversation;
use App\Ai\AiUsage;
use App\Apps\CurrentApp;
use App\Apps\Definition\AppInstaller;
use App\DynamicData\Assistant\TableAssistant;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\AppDraft;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Role;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use App\Settings\AppSettings;
use App\Support\IconCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * KI-App-Designer (B-107, Etappe 7a): Aus einer Beschreibung entsteht im Gespräch ein Entwurf einer ganzen App –
 * Tabellen, Felder, Verknüpfungen, berechnete Felder, Regeln, Menüs. Der Entwurf wird geprüft (DraftCompiler) und als
 * App-Definition gespeichert, aber erst mit create() angelegt (AppInstaller), samt Fachrolle; die App ist danach aktiv.
 *
 * An die KI gehen nur Beschreibung, Entwurf und die Struktur freigegebener Tabellen anderer Apps – nie Datensätze.
 */
final class AppDesigner
{
    /** Frühere Runden im Gespräch (je Frage und Antwort). */
    private const int HISTORY_TURNS = 4;

    private const string INSTRUCTIONS = <<<'TXT'
        You design a complete business application ("app") for SAFT, a database application. Respond only with JSON that
        matches the given schema.

        Languages: write every label, description, choice label and menu label ONLY in the language of the user (the
        language code given in the request) and set all other languages to "" – they are translated automatically when
        the app is created. This overrides "in all languages" below.

        An app has a name and a one-sentence description in all languages of the schema (de, en, fr, it – translate
        them), a prefix (lower case letters and digits, 1-20 characters, starting with a letter, e.g. "gitarren"; it
        must not be one of "existing_prefixes") and an icon from "icons".

        Tables: the technical name is the prefix, an underscore and a noun in the language of the user (e.g.
        "gitarren_artikel"), lower case letters, digits and underscores, at most 63 characters; label and description in
        all languages; an icon from "icons". List tables so that tables a relation points to come before the tables that
        point to them. Every table automatically has an id and timestamps – never add them.
        TXT;

    private const string APP_RULES = <<<'TXT'
        Relations: between tables of this app (relation_table = full technical name, e.g. "gitarren_belege"), or to shared
        tables of other apps listed in "shared_tables" (relation_display only from their shared fields). Put the relation
        into the child table (e.g. booking lines → receipt). Model data that is referenced repeatedly (customers,
        suppliers, items, categories) as tables of their own instead of free text. Computed fields may sum over child
        tables of this app – tables whose relation points to the table – with the full table name, e.g.
        sum(gitarren_buchungen.betrag); a computed field may only use fields listed before it in its table, sums may use
        computed fields of the child table. Computed fields cannot read fields of a parent table.

        Table rules go into "rules" of the table as "<field> <operator> <field or value>" with the operators =, <>, <, <=,
        >, >=, e.g. "ende >= beginn" or "betrag > 0".

        Menus: group the tables into 1-4 menu groups (label in all languages, icon, tables = technical names), e.g. daily
        work and master data; each table in at most one group. A data model entry and a role with all permissions are
        added automatically – do not model users, roles or permissions.

        Reports: propose 2-6 reports in the user's language – first those the description asks for explicitly (e.g.
        "income and expenses per year with balance"), then obvious ones. Each has a short title and one or two sentences
        describing exactly what it shows: base table, grouping, values, filters, sort, kind of chart (e.g. "Bookings
        grouped by year of the receipt date: sum of income, sum of expenses and their difference as balance; as a
        table"). Use the field names of the draft. The reports are created after the app exists – keep them to what the
        tables and computed fields can deliver.

        Keep the app focused: what the description asks for plus obvious necessities, no speculative extras. When the
        request is too vague to design a useful app, set kind to "question" and ask one short question in the user's
        language; fill the remaining properties with empty values. Each request includes the current draft (state):
        change it as the user asks and keep everything else unchanged; without a draft, design a new app.
        TXT;

    /** Hinweis des letzten create(), wenn die Übersetzung nicht gelang (die App ist trotzdem erzeugt). */
    public ?string $translationWarning = null;

    public function __construct(
        private readonly AssistantClient $client,
        private readonly DraftCompiler $compiler,
        private readonly AppInstaller $installer,
        private readonly DraftTranslator $translator,
    ) {}

    public static function availableFor(?User $user): bool
    {
        return app(AiConfig::class)->configured() && app(AppSettings::class)->ai_assistant_enabled && ($user?->can('apps.design') ?? false);
    }

    /** Neuer, leerer Entwurf. */
    public function start(User $user): AppDraft
    {
        return AppDraft::query()->create(['user_id' => $user->id, 'title' => __('app_designer.untitled'), 'status' => AppDraft::DRAFT]);
    }

    /**
     * Eine Runde im Gespräch: Vorschlag (geprüft und als Definition gespeichert), Rückfrage oder Fehler.
     *
     * @throws BusinessRuleException
     */
    public function propose(User $user, AppDraft $draft, string $text): DesignerResult
    {
        $this->assertCanAsk($user, $draft, $text);
        $compiled = null;
        $system = [
            ['type' => 'text', 'text' => self::INSTRUCTIONS."\n\n".TableAssistant::FIELD_INSTRUCTIONS."\n\n".self::APP_RULES],
            ['type' => 'text', 'text' => "Context:\n".json_encode($this->context(), JSON_UNESCAPED_UNICODE), 'cache' => true],
        ];
        $request = [
            'role' => 'user',
            'content' => 'Language of the user: '.app()->getLocale().".\n"
                .'Current draft: '.($draft->proposal === null ? 'none' : json_encode($draft->proposal, JSON_UNESCAPED_UNICODE))."\n\n"
                .'Request: '.trim($text),
        ];
        $conversation = new AiConversation($this->client);
        // Eine ganze App ist eine lange Antwort (oft 2–3 Minuten, bei grossen Apps und langsamen Modellen deutlich mehr):
        // eigene, höhere Grenzen für Länge und Dauer, für die Anfrage Platz für eine Korrekturrunde.
        $timeout = max((int) config('ai.timeout', 120), (int) config('ai.designer_timeout', 900));
        config([
            'ai.timeout' => $timeout,
            'ai.max_tokens' => max((int) config('ai.max_tokens', 16000), (int) config('ai.designer_max_tokens', 32000)),
        ]);
        @set_time_limit(2 * $timeout + 60);

        try {
            // Das Schema einer ganzen App ist dem Anbieter als Grammatik zu gross („grammar too large“): nur als Vorgabe
            // im Auftrag (strict: false) – geprüft wird der Vorschlag ohnehin vollständig, Fehler gehen in die Korrekturrunde.
            $answer = $conversation->ask($system, [...array_slice((array) ($draft->history ?? []), -2 * self::HISTORY_TURNS), $request], DesignerSchema::response(), function (array $proposal) use (&$compiled): array {
                $compiled = $this->compiler->compile($proposal);

                return $compiled['problems'];
            }, strict: false);
        } catch (Throwable $e) {
            Log::warning('App-Designer fehlgeschlagen', ['error' => $e->getMessage()]);
            AiUsage::log($user, 'apps', $text, 'error', $conversation->usage, $conversation->model(), $e->getMessage());

            throw new BusinessRuleException(__('assistant.errors.failed'), previous: $e);
        }

        $result = match ($answer['status']) {
            AiConversation::OK => new DesignerResult(DesignerResult::PROPOSAL, warnings: $compiled['warnings'] ?? []),
            AiConversation::QUESTION => new DesignerResult(DesignerResult::QUESTION, (string) $answer['question']),
            AiConversation::REFUSED => new DesignerResult(DesignerResult::ERROR, __('assistant.errors.refused')),
            AiConversation::TRUNCATED => new DesignerResult(DesignerResult::ERROR, __('app_designer.errors.too_large', ['tokens' => (int) config('ai.max_tokens')])),
            default => new DesignerResult(DesignerResult::ERROR, __('app_designer.errors.invalid', ['errors' => implode('; ', array_slice($answer['errors'], 0, 8))])),
        };

        if ($result->status !== DesignerResult::ERROR) {
            $draft->fill([
                'history' => $this->shorten($answer['history'], $request),
                // Erste Beschreibung als Titel des Entwurfs.
                'title' => $draft->proposal === null && $draft->title === __('app_designer.untitled') ? Str::limit(trim($text), 80) : $draft->title,
                ...($result->status === DesignerResult::PROPOSAL ? ['proposal' => $answer['proposal'], 'definition' => $compiled['definition']] : []),
            ])->save();
        }

        AiUsage::log($user, 'apps', $text, $result->status, $conversation->usage, $conversation->model(), $result->status === DesignerResult::ERROR ? $result->message : null);

        return $result;
    }

    /**
     * App anlegen: Definition installieren (Name änderbar; ein anderes Präfix benennt die Tabellen um), aktivieren und
     * in die App wechseln. Die vorgeschlagenen Auswertungen warten danach auf DraftReports (Etappe 7c). Wer kein
     * Super-Admin ist, erhält die Fachrolle – sonst sähe er die Tabellen seiner App nicht.
     *
     * @throws BusinessRuleException
     */
    /**
     * @param  bool|list<int>  $reports  true = alle vorgeschlagenen Auswertungen, false bzw. [] = keine, sonst die Indizes
     */
    public function create(User $user, AppDraft $draft, ?string $name = null, ?string $prefix = null, bool|array $reports = true): App
    {
        abort_unless($user->can('apps.design') && $draft->user_id === $user->id, 403);

        if ($draft->status !== AppDraft::DRAFT || ! is_array($draft->definition)) {
            throw new BusinessRuleException(__('app_designer.errors.nothing_to_create'));
        }

        // Vor dem Anlegen noch einmal prüfen: Seit dem Vorschlag kann z. B. ein Tabellenname vergeben worden sein.
        $compiled = $this->compiler->compile((array) $draft->proposal);

        if ($compiled['problems'] !== [] || $compiled['definition'] === null) {
            throw new BusinessRuleException(implode(' ', $compiled['problems']));
        }

        // Im Gespräch entstehen die Bezeichnungen nur in einer Sprache; die übrigen jetzt einmal übersetzen.
        $timeout = max((int) config('ai.timeout', 120), (int) config('ai.designer_timeout', 900));
        config(['ai.timeout' => $timeout]);
        @set_time_limit(2 * $timeout + 60);
        $translated = $this->translator->translate($user, $compiled['definition']);
        $definition = $translated['definition'];
        $this->translationWarning = $translated['warning'];
        $default = (string) config('locales.default');
        $prefix = filled($prefix) ? strtolower(trim((string) $prefix)) : $definition['app']['prefix'];

        $locale = app()->getLocale();

        // Der Name aus dem Dialog gilt in der Sprache der Person; geändert, gilt er auch für die übrigen ohne eigenen Namen.
        if (filled($name) && trim((string) $name) !== (string) ($definition['app']['name'][$locale] ?? '')) {
            $definition['app']['name'] = [...$definition['app']['name'], $locale => trim((string) $name)];
        }

        $copy = $prefix !== $definition['app']['prefix'] ? ['name' => (string) ($definition['app']['name'][$default] ?? $definition['app']['name'][$locale] ?? $name), 'prefix' => $prefix] : null;
        $app = $this->installer->install($definition, $copy, $user);
        $app->forceFill(['is_active' => true])->save();

        if (! $user->isSuperAdmin() && ($role = Role::query()->where('app_id', $app->id)->first()) !== null) {
            $user->assignRole($role);
        }

        app(CurrentApp::class)->switch($user->refresh(), $app);

        $draft->forceFill([
            'status' => AppDraft::CREATED,
            'app_id' => $app->id,
            'reports' => DraftReports::fromProposal((array) $draft->proposal, $reports),
        ])->save();

        return $app;
    }

    private function assertCanAsk(User $user, AppDraft $draft, string $text): void
    {
        if (! self::availableFor($user) || $draft->user_id !== $user->id) {
            throw new BusinessRuleException(__('assistant.errors.not_available'));
        }

        if ($draft->status !== AppDraft::DRAFT) {
            throw new BusinessRuleException(__('app_designer.errors.already_created'));
        }

        if (trim($text) === '' || mb_strlen(trim($text)) > 4000) {
            throw new BusinessRuleException(__('assistant.errors.prompt_length', ['max' => 4000]));
        }

        if (($limit = AiUsage::limitMessage($user)) !== null) {
            throw new BusinessRuleException($limit);
        }
    }

    /**
     * Was die KI ausser dem Entwurf kennt: vergebene Präfixe, freigegebene Tabellen anderer Apps, Symbole.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'existing_prefixes' => App::query()->whereNotNull('prefix')->pluck('prefix')->map(static fn (string $p): string => rtrim($p, '_'))->values()->all(),
            'shared_tables' => CustomTable::query()->designer()->where('is_shared', true)->where('is_active', true)->with(['fields', 'app'])->orderBy('name')->get()
                ->map(static fn (CustomTable $t): array => [
                    'name' => $t->name,
                    'label' => (string) $t->label,
                    'app' => (string) $t->app?->name,
                    'shared_fields' => $t->fields->filter(static fn (CustomField $f): bool => $f->is_active && $f->is_shared)
                        ->map(static fn (CustomField $f): array => ['name' => $f->name, 'type' => $f->type->value])->values()->all(),
                ])->values()->all(),
            'icons' => IconCatalog::assistantNames(),
        ];
    }

    /**
     * Verlauf zum Speichern: Fragen im Wortlaut, frühere Vorschläge nur als Kurzfassung – den vollständigen Stand
     * bekommt die KI ohnehin mit jeder Frage („Current draft“).
     *
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{role: string, content: string}  $request
     * @return list<array{role: string, content: string}>
     */
    private function shorten(array $history, array $request): array
    {
        return array_map(static function (array $message) use ($request): array {
            if ($message === $request) {
                return ['role' => 'user', 'content' => (string) Str::after($message['content'], 'Request: ')];
            }

            if ($message['role'] !== 'assistant') {
                return $message;
            }

            $data = json_decode($message['content'], true);

            if (! is_array($data) || ($data['kind'] ?? null) === 'question') {
                return $message;
            }

            return ['role' => 'assistant', 'content' => 'Proposal with the tables: '.implode(', ', array_column((array) ($data['tables'] ?? []), 'name')).'.'];
        }, $history);
    }
}
