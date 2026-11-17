<?php

declare(strict_types=1);

namespace App\Help;

use App\Ai\AiConfig;
use App\Ai\AiUsage;
use App\Ai\ChatClient;
use App\Ai\ChatReply;
use App\Documentation\Documentation;
use App\DynamicData\DataPermissions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\AiHelpConversation;
use App\Models\AiHelpMessage;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaMarkdown;
use App\Settings\AppSettings;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Hilfe-Assistent: beantwortet Fragen aus den Wissensquellen, die die Person sehen darf (heute die Handbücher der
 * Online-Hilfe), auf Wunsch mit dem Datenmodell (Tabellen und Felder mit Leserecht) – nie mit Datensätzen. Die
 * Unterhaltungen werden gespeichert; ans Modell gehen nur die letzten Nachrichten, die Handbücher zwischengespeichert.
 */
final class HelpAssistant
{
    public const int MAX_QUESTION = 2000;

    /** Nachrichten des Verlaufs, die mit einer neuen Frage mitgehen (je Frage und Antwort eine). */
    public const int HISTORY = 6;

    private const array LANGUAGES = ['de' => 'German (Swiss spelling: ss instead of ß)', 'en' => 'English', 'fr' => 'French', 'it' => 'Italian'];

    public function __construct(
        private readonly ChatClient $client,
        private readonly KnowledgeSources $sources,
        private readonly AiConfig $config,
        private readonly AppSettings $settings,
    ) {}

    /** Darf diese Person den Assistenten nutzen (eingerichtet, eingeschaltet, Recht)? */
    public function availableFor(?User $user): bool
    {
        return $user instanceof User
            && $this->config->configured()
            && $this->settings->ai_help_enabled
            && $user->can('help.assistant');
    }

    /**
     * Frage stellen; ohne Unterhaltung beginnt eine neue. Gibt die Unterhaltung mit der neuen Antwort zurück.
     *
     * @param  array{title?: string, path?: string}|null  $page  Seite, auf der die Person gerade ist
     *
     * @throws BusinessRuleException mit einer Meldung für die Anzeige
     */
    public function ask(User $user, ?AiHelpConversation $conversation, string $question, ?array $page = null): AiHelpConversation
    {
        $question = trim($question);

        if (! $this->availableFor($user)) {
            throw new BusinessRuleException(__('assistant.errors.not_available'));
        }

        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION) {
            throw new BusinessRuleException(__('assistant.errors.prompt_length', ['max' => self::MAX_QUESTION]));
        }

        if (($limit = AiUsage::limitMessage($user)) !== null) {
            throw new BusinessRuleException($limit);
        }

        if ($conversation !== null && $conversation->user_id !== $user->getKey()) {
            throw new BusinessRuleException(__('assistant.errors.not_available'));
        }

        $sources = $this->sources->for($user);
        $history = $conversation === null ? [] : $conversation->messages()->get()->slice(-self::HISTORY)
            ->map(static fn (AiHelpMessage $m): array => ['role' => $m->role, 'content' => $m->content])->values()->all();

        try {
            $reply = $this->client->chat($this->system($user, $sources), [...$history, ['role' => 'user', 'content' => $this->withPage($question, $page)]]);
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Hilfe-Assistent: Anfrage fehlgeschlagen', ['error' => $e->getMessage()]);
            AiUsage::log($user, 'help', $question, 'error', ['in' => 0, 'out' => 0, 'cache' => 0], $this->client->model(), $e->getMessage());

            throw new BusinessRuleException(__('assistant.errors.failed'), previous: $e);
        }

        if ($reply->stopReason === ChatReply::REFUSAL || blank($reply->text)) {
            AiUsage::log($user, 'help', $question, 'refused', $reply->usage(), $this->client->model());

            throw new BusinessRuleException(__('assistant.errors.refused'));
        }

        [$answer, $cited] = $this->checkLinks((string) $reply->text, $sources, array_keys($this->menu($user)));

        $conversation ??= AiHelpConversation::query()->create([
            'user_id' => $user->getKey(),
            'title' => Str::limit(preg_replace('/\s+/u', ' ', $question) ?? $question, 120),
            'language' => app()->getLocale(),
        ]);

        $conversation->ai_help_messages()->create(['role' => AiHelpMessage::USER, 'content' => $question]);
        $conversation->ai_help_messages()->create([
            'role' => AiHelpMessage::ASSISTANT,
            'content' => $answer,
            'sources' => $cited,
            'model' => $this->client->model(),
            'tokens_in' => $reply->inputTokens,
            'tokens_out' => $reply->outputTokens,
            'tokens_cache' => $reply->cacheReadTokens,
        ]);
        $conversation->touch();
        AiUsage::log($user, 'help', $question, 'ok', $reply->usage(), $this->client->model());

        return $conversation;
    }

    /**
     * Antwort als HTML: Markdown ohne eingebettetes HTML; Verweise auf Abschnitte führen in die Online-Hilfe.
     */
    public function html(User $user, string $answer, bool $onDocumentationPage = false): string
    {
        $urls = [];

        foreach ($this->sources->for($user) as $source) {
            foreach (array_keys($source->anchors()) as $anchor) {
                $urls['#'.$anchor] = $source->url($anchor);
            }
        }

        $html = Documentation::markdown($answer);

        return preg_replace_callback('/<a href="([^"]*)"/', static function (array $m) use ($urls, $onDocumentationPage): string {
            $href = rawurldecode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));

            return match (true) {
                // Seite aus dem Menü (beim Speichern geprüft): im selben Fenster öffnen.
                str_starts_with($href, '/') => '<a href="'.e($href).'" data-help-page',
                ! isset($urls[$href]) => $m[0].' target="_blank" rel="noopener"',
                // Auf der Seite „Dokumentation“ springt der Verweis in den Abschnitt, ohne die Seite neu zu laden.
                $onDocumentationPage => '<a href="'.e($href).'" data-doc-link data-help-source',
                default => '<a href="'.e($urls[$href]).'" data-help-source',
            };
        }, $html) ?? $html;
    }

    /**
     * Anweisungen, Wissensquellen und – eingeschaltet – das Datenmodell. Unveränderliche Teile zuerst und zum
     * Zwischenspeichern markiert: Für dieselbe Sprache und Rolle sind sie bei jeder Frage gleich.
     *
     * @param  list<KnowledgeSource>  $sources
     * @return list<array{text: string, cache?: bool}>
     */
    private function system(User $user, array $sources): array
    {
        $language = self::LANGUAGES[app()->getLocale()] ?? 'English';
        $knowledge = array_map(static fn (KnowledgeSource $s): string => "<document id=\"{$s->id()}\" title=\"{$s->label()}\">\n{$s->markdown()}\n</document>", $sources);

        $system = [[
            'text' => implode("\n", [
                'You are the help assistant of SAFT XT, a platform for database applications. You answer questions of',
                'the person using this installation about how to work with it.',
                '',
                'Rules:',
                '- Answer only from the documentation below (and the data model, if given). If it does not cover the',
                '  question, say so briefly and suggest asking the administration. Never invent menu entries, buttons,',
                '  permissions or features.',
                "- Answer in {$language}, briefly: a short answer first, then numbered steps where useful. Use the exact",
                '  terms of the user interface as written in the documentation (bold for buttons and menu entries).',
                '- Cite the sections you used as Markdown links to their anchor, e.g. [4.1 Title](#user-41-title). Use',
                '  only anchors that appear as {#…} in the documentation.',
                '- You cannot see or change any records. Some functions need permissions the person may not have. The',
                '  roles and permissions of the person asking are listed at the end: when something seems missing or',
                '  does not work, check them first; if a permission is missing, name it exactly as listed and refer',
                '  the person to the administration.',
                '- The page the person is on may be given in brackets before the question; use it to resolve “here”.',
                '- For questions like “where can I …”, link the page from the person’s menu (listed at the end) as a',
                '  Markdown link with its path, e.g. [Administration › Users](/admin/users). Use only paths from that',
                '  list; pages not in it (e.g. dialogs like “New”) are described in words.',
                '',
                ...$knowledge,
            ]),
            'cache' => true,
        ]];

        if ($this->settings->ai_help_schema && ($schema = $this->schema($user)) !== '') {
            $system[] = [
                'text' => "Data model of this installation: the tables and fields this person may view (no records).\n"
                    ."Use it for questions like where something is entered or which fields a table has.\n\n".$schema,
                'cache' => true,
            ];
        }

        // Rollen und Rechte der fragenden Person (in der Rollen-Simulation die simulierten) – je Person anders, darum
        // zuletzt und nicht zwischengespeichert; keine anderen Personen, keine Daten.
        $system[] = ['text' => $this->permissions($user)];

        if (($menu = $this->menu($user)) !== []) {
            $system[] = ['text' => "Menu of the person asking (what they see in the sidebar; path → address):\n"
                .implode("\n", array_map(static fn (string $url, string $label): string => $label.' → '.$url, array_keys($menu), $menu))];
        }

        return $system;
    }

    /**
     * Rollen, alle allgemeinen Rechte mit „hat / hat nicht“ (Bezeichnungen wie in der Rollenverwaltung) und die
     * vorhandenen Datenrechte – damit der Assistent sagen kann, welches Recht für etwas fehlt.
     */
    private function permissions(User $user): string
    {
        $roles = $user->roles->pluck('name')->sort()->values()->all();
        $lines = [
            'Roles and permissions of the person asking (labels as in the role administration; ✓ = has, ✗ = lacks):',
            'Roles: '.($roles === [] ? '–' : implode(', ', $roles)),
        ];

        if ($user->isSuperAdmin()) {
            return implode("\n", [...$lines, 'The role super-admin has every permission.']);
        }

        $names = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
        $general = array_filter($names, static fn (string $name): bool => ! str_starts_with($name, DataPermissions::PREFIX));
        $data = array_filter($names, static fn (string $name): bool => str_starts_with($name, DataPermissions::PREFIX) && $user->can($name));

        $lines[] = 'General permissions:';

        foreach ($general as $name) {
            $lines[] = ($user->can($name) ? '✓ ' : '✗ ').RoleForm::label($name);
        }

        $lines[] = 'Data permissions held (all others are lacking):';
        $lines[] = $data === [] ? '–' : implode('; ', array_map(static fn (string $name): string => RoleForm::label($name), $data));

        return implode("\n", $lines);
    }

    /**
     * Menü der Person, wie es die Seitenleiste zeigt (Rechte, Rollen-Simulation, ausgeschaltete Plugins): Adresse ohne
     * Server => „Gruppe › Eintrag“.
     *
     * @return array<string, string>
     */
    private function menu(User $user): array
    {
        $entries = [];
        $add = function (NavigationItem $item, string $prefix) use (&$add, &$entries): void {
            $label = trim($prefix.strip_tags((string) $item->getLabel()));

            // Unterpunkte zuerst: Ein Untermenü trägt die Adresse seiner ersten Seite, die so ihren vollen Pfad behält
            // („Administration › Benutzer und Zugriff › Benutzer“ statt nur des Untermenüs).
            foreach ($item->getChildItems() as $child) {
                $add($child, $label.' › ');
            }

            $url = $item->getUrl();

            if (is_string($url) && $url !== '') {
                $parts = parse_url($url);
                $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
                $entries[$path] ??= $label;
            }
        };

        foreach (app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user)->getNavigation() as $group) {
            $prefix = filled($group->getLabel()) ? strip_tags((string) $group->getLabel()).' › ' : '';

            foreach ($group->getItems() as $item) {
                $add($item, $prefix);
            }
        }

        return $entries;
    }

    /** Tabellen mit Leserecht als kompakte Beschreibung; Werte von Auswahlfeldern nur, wenn freigegeben. */
    private function schema(User $user): string
    {
        $exporter = app(SchemaExporter::class);
        $schema = $exporter->export();
        $schema['tables'] = array_values(array_filter(
            $schema['tables'],
            static fn (array $t): bool => ($t['is_active'] ?? true) && $user->can(DataPermissions::name((string) $t['name'], 'view')),
        ));

        if ($schema['tables'] === []) {
            return '';
        }

        if (! $this->settings->ai_send_choices) {
            foreach ($schema['tables'] as &$table) {
                foreach ($table['fields'] as &$field) {
                    if (($field['type'] ?? null) === 'select' && is_array($field['options'] ?? null)) {
                        unset($field['options']['options']);
                    }
                }
                unset($field);
            }
            unset($table);
        }

        return app(SchemaMarkdown::class)->tables($schema);
    }

    /** @param  array{title?: string, path?: string}|null  $page */
    private function withPage(string $question, ?array $page): string
    {
        $title = trim((string) ($page['title'] ?? ''));

        return $title === '' ? $question : '[Current page: '.$title.' ('.trim((string) ($page['path'] ?? '')).")]\n\n".$question;
    }

    /**
     * Verweise auf Abschnitte prüfen: unbekannte Anker werden zu Text, bekannte als Quellen gesammelt.
     *
     * @param  list<KnowledgeSource>  $sources
     * @param  list<string>  $menu  Adressen aus dem Menü der Person
     * @return array{0: string, 1: list<array{source: string, anchor: string, title: string}>}
     */
    private function checkLinks(string $answer, array $sources, array $menu = []): array
    {
        $known = [];

        foreach ($sources as $source) {
            foreach ($source->anchors() as $anchor => $title) {
                $known[$anchor] = ['source' => $source->id(), 'anchor' => $anchor, 'title' => $title];
            }
        }

        $cited = [];
        $answer = preg_replace_callback('/\[([^\]]+)\]\(#([^)\s]+)\)/u', static function (array $m) use ($known, &$cited): string {
            $anchor = rawurldecode($m[2]);

            if (! isset($known[$anchor])) {
                return $m[1];
            }

            $cited[$anchor] = $known[$anchor];

            return '['.$m[1].'](#'.$anchor.')';
        }, $answer) ?? $answer;

        // Übrige Links: nur Seiten aus dem Menü der Person bleiben Link, alles andere (erfundene Adressen, Links nach
        // aussen) wird zu Text.
        $answer = preg_replace_callback('/\[([^\]]+)\]\(([^#)\s][^)\s]*)\)/u', static fn (array $m): string => in_array($m[2], $menu, true) ? $m[0] : $m[1], $answer) ?? $answer;

        return [$answer, array_values($cited)];
    }
}
