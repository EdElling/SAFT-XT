<?php

declare(strict_types=1);

namespace App\Documents\Assistant;

use App\Ai\AiConfig;
use App\Ai\AiConversation;
use App\Ai\AiUsage;
use App\Apps\AppPermissions;
use App\Documents\LetterheadResolver;
use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateFields;
use App\Documents\Templates\TemplateSkeletons;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use App\Settings\AppSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KI-Assistent für Dokumentvorlagen (B-124, D2a): Beschreibung in eigenen Worten → Empfänger, Inhalt (HTML mit
 * Platzhaltern) und Dateiname. Erzeugt nur Inhaltsvorlagen, nie das Briefpapier.
 *
 * Übertragen werden nur die Beschreibung, der aktuelle Stand der Vorlage und der Platzhalter-Katalog der Grundtabelle
 * (Felder, Verknüpfungen, Kind-Tabellen; Auswahlwerte nur mit der Einstellung „Werte von Auswahlfeldern übertragen“) –
 * nie Datensätze. Der Vorschlag läuft durch die Prüfung der Vorlagen; Fehler und Hinweise gehen einmal zur Korrektur
 * zurück. Jede Anfrage steht im Änderungsprotokoll (Bereich „documents“), es gilt das gemeinsame Tageslimit.
 */
final class TemplateAssistant
{
    public const string PERMISSION = 'documents.assistant';

    public const int MAX_PROMPT = 2000;

    private const int HISTORY_TURNS = 6;

    private const string INSTRUCTIONS = <<<'TXT'
        You write content templates for documents (invoices, letters, confirmations, data sheets) in SAFT, a database
        application. SAFT renders them as PDF on the letterhead of the organisation. Respond only with JSON that matches
        the given schema.

        The letterhead (logo, sender, colours, fonts, footer, page numbers) is added by SAFT – never put a logo, letterhead,
        page numbers, <html>, <head>, <body> or <style> blocks into the template. The template has three parts:
        - recipient: the lines of the address window, one per line, with placeholders; empty lines are dropped. SAFT
          places it in the address field; the body starts below it. Never repeat the address in the body. Leave it ""
          for documents without recipient (e.g. a data sheet).
        - body: simple HTML with placeholders: h1, h2, p, br, strong, em, table/tr/th/td (with width="40%" etc.), ul/li.
          Layouts only with tables – no flexbox, grid, float, position or @media; no images from outside, no scripts.
          CSS classes of the letterhead: primaer, sekundaer (colours), klein, fett, rechts; a table with class
          "positionen" gets a coloured header row repeated on every page, a row with class "summe" a line above it.
          Inline style is allowed for simple things (text-align, width, padding, font-size).
        - file_name: the PDF file name without ".pdf", with placeholders, e.g. "Rechnung-{{belegnummer}}".
        Also give the template a short name in the user's language (name).

        Placeholders are Mustache: {{field}} shows the value as in SAFT (formatted number, date, label of a choice,
        display of a relation). Fields of the base table by technical name; fields of a related record with a dot,
        e.g. {{kunde_id.ort}} (at most 3 steps). Child tables ("children" in the data model) repeat a block per child
        record: {{#section}} … {{/section}} with the child's fields inside, e.g. one table row per item; fields of a
        child table are only valid inside its section. {{#field}} … {{/field}} shows a block only when the field has a
        value (yes/no: when yes); {{^name}} … {{/name}} only when it is empty or there are no child records. Sender data:
        {{absender.firma}}, {{absender.adresse | Zeilen}}, {{absender.mwst}}, {{absender.iban}}, {{absender.kontakt}}.
        {{heute}} is the date of creation. Formats follow a "|" and are capitalised: CHF (amount with 2 decimals), Zahl
        or Zahl:n (number, n decimals), Datum, DatumLang (date short or long), Zeilen (keep line breaks, for long text
        and addresses), Roh (stored value), Gross (upper case), e.g. {{total | CHF}}, {{datum | DatumLang}}. No other
        formats, no partials ({{> …}}), no logic.

        Templates never calculate: totals, VAT, balances come from computed fields of the tables – use them. If a value
        the user asks for does not exist as a field, leave it out and say so in "note" (it can be added as a computed
        field in the table designer). Use only fields from the data model; never invent names.

        Fixed text (headings, salutation, closing) in the user's language unless the user asks for another. Keep it
        businesslike and short. The example shows the style SAFT's own templates use.

        Each request includes the current template (it may be empty or edited by hand): change it when the user asks
        for a change and keep everything else as it is; otherwise start over. In "note" summarise in one or two
        sentences in the user's language what you did or what is missing. When the request is ambiguous, set kind to
        "question", ask one short question in the user's language and leave the other properties "".
        TXT;

    public function __construct(
        private readonly AssistantClient $client,
        private readonly TemplateChecker $checker,
    ) {}

    /** Konfiguriert, eingeschaltet und Recht „Dokumente: Assistent nutzen“ in der App (ohne App: in irgendeiner). */
    public static function availableFor(?User $user, ?int $appId): bool
    {
        if ($user === null || ! app(AiConfig::class)->configured() || ! app(AppSettings::class)->ai_assistant_enabled) {
            return false;
        }

        return AppPermissions::can($user, self::PERMISSION, $appId);
    }

    /**
     * @param  array{name?: ?string, recipient?: ?string, body?: ?string, file_name?: ?string}  $current
     * @param  list<array{role: string, content: string}>  $history
     */
    public function propose(User $user, CustomTable $table, string $text, array $current, array $history = []): TemplateAssistantResult
    {
        $text = trim($text);

        if (! self::availableFor($user, $table->app_id)) {
            throw new BusinessRuleException(__('assistant.errors.not_available'));
        }

        if ($text === '' || mb_strlen($text) > self::MAX_PROMPT) {
            throw new BusinessRuleException(__('assistant.errors.prompt_length', ['max' => self::MAX_PROMPT]));
        }

        if (($limit = AiUsage::limitMessage($user)) !== null) {
            throw new BusinessRuleException($limit);
        }

        $choices = app(AppSettings::class)->ai_send_choices;
        $system = [
            ['type' => 'text', 'text' => self::INSTRUCTIONS],
            ['type' => 'text', 'text' => "Data model:\n".json_encode($this->model($table, $choices), JSON_UNESCAPED_UNICODE)
                ."\n\nExample (a starting point SAFT generates for this table):\n".json_encode($this->example($table), JSON_UNESCAPED_UNICODE), 'cache' => true],
        ];
        $request = [
            'role' => 'user',
            'content' => 'Language of the user: '.app()->getLocale().".\n"
                .'Current template: '.json_encode($this->current($current), JSON_UNESCAPED_UNICODE)."\n\n"
                .'Request: '.$text,
        ];
        $messages = [...array_slice($history, -2 * self::HISTORY_TURNS), $request];
        $conversation = new AiConversation($this->client);

        try {
            $answer = $conversation->ask($system, $messages, self::schema(), fn (array $proposal): array => $this->problems($table, $proposal));
        } catch (Throwable $e) {
            Log::warning('KI-Assistent für Dokumentvorlagen fehlgeschlagen', ['error' => $e->getMessage()]);
            AiUsage::log($user, 'documents', $text, 'error', $conversation->usage, $conversation->model(), $e->getMessage());

            throw new BusinessRuleException(__('assistant.errors.failed'), previous: $e);
        }

        $result = match ($answer['status']) {
            AiConversation::OK => new TemplateAssistantResult(TemplateAssistantResult::TEMPLATE, template: [
                'name' => trim((string) ($answer['proposal']['name'] ?? '')),
                'recipient' => trim((string) ($answer['proposal']['recipient'] ?? '')),
                'body' => trim((string) $answer['proposal']['body'])."\n",
                'file_name' => trim((string) ($answer['proposal']['file_name'] ?? '')),
            ], message: filled($answer['proposal']['note'] ?? null) ? (string) $answer['proposal']['note'] : null, history: $answer['history']),
            AiConversation::QUESTION => new TemplateAssistantResult(TemplateAssistantResult::QUESTION, message: $answer['question'], history: $answer['history']),
            AiConversation::REFUSED => new TemplateAssistantResult(TemplateAssistantResult::ERROR, message: __('assistant.errors.refused')),
            default => new TemplateAssistantResult(TemplateAssistantResult::ERROR, message: __('assistant.errors.invalid', ['errors' => implode('; ', $answer['errors'])])),
        };

        AiUsage::log($user, 'documents', $text, $result->status, $conversation->usage, $conversation->model(), $result->status === TemplateAssistantResult::ERROR ? $result->message : null);

        return $result;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['template', 'question']],
                'question' => $string,
                'name' => $string,
                'recipient' => $string,
                'body' => $string,
                'file_name' => $string,
                'note' => $string,
            ],
            'required' => ['kind', 'question', 'name', 'recipient', 'body', 'file_name', 'note'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Fehler und Hinweise der Prüfung – auch Hinweise gehen zur Korrektur zurück, damit das HTML im PDF taugt.
     *
     * @param  array<string, mixed>  $proposal
     * @return list<string>
     */
    private function problems(CustomTable $table, array $proposal): array
    {
        if (blank($proposal['body'] ?? null)) {
            return [__('documents.assistant.empty_body')];
        }

        $check = $this->checker->check($table, (string) $proposal['body'], (string) ($proposal['recipient'] ?? ''), (string) ($proposal['file_name'] ?? ''));

        return [...$check['errors'], ...$check['warnings']];
    }

    /**
     * Platzhalter-Katalog: Felder der Grundtabelle, Felder der verknüpften Datensätze (ein Schritt) und Kind-Tabellen.
     *
     * @return array<string, mixed>
     */
    private function model(CustomTable $table, bool $choices): array
    {
        $fields = fn (CustomTable $t, ?CustomField $skip = null): array => TemplateFields::fields($t)
            ->reject(static fn (CustomField $f): bool => $skip !== null && $f->is($skip))
            ->map(fn (CustomField $f): array => $this->field($f, $choices))->values()->all();

        $relations = [];

        foreach (TemplateFields::fields($table) as $field) {
            if (($target = TemplateFields::target($field)) !== null) {
                $relations[$field->name] = ['table' => (string) $target->label, 'fields' => $fields($target)];
            }
        }

        $children = [];

        foreach (TemplateFields::children($table) as $section => $relation) {
            $children[$section] = ['table' => (string) $relation->customTable->label, 'fields' => $fields($relation->customTable, $relation)];
        }

        return [
            'base_table' => ['name' => $table->name, 'label' => (string) $table->label, 'fields' => $fields($table)],
            'relations' => $relations,
            'children' => $children,
            'sender' => LetterheadResolver::SENDER_KEYS,
        ];
    }

    /** @return array<string, mixed> */
    private function field(CustomField $field, bool $choices): array
    {
        $type = $field->valueType();
        $data = ['name' => $field->name, 'label' => (string) $field->label, 'type' => $type->value];

        if ($type === FieldType::Relation && ($target = TemplateFields::target($field)) !== null) {
            $data['to'] = (string) $target->label;
        }

        if ($field->type === FieldType::Computed) {
            $data['computed'] = true;
        }

        // Auswahlwerte nur mit der Einstellung „Werte von Auswahlfeldern übertragen“ – sie verraten Einträge.
        if ($choices && $type === FieldType::Select) {
            $data['choices'] = array_map(static fn (array $o): string => (string) ($o['value'] ?? ''), array_values(array_filter((array) ($field->optionsArray()['options'] ?? []), 'is_array')));
        }

        return $data;
    }

    /** @return array{recipient: string, body: string, file_name: string} */
    private function example(CustomTable $table): array
    {
        return TemplateSkeletons::make(TemplateFields::children($table) !== [] ? TemplateSkeletons::INVOICE : TemplateSkeletons::LETTER, $table);
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{name: string, recipient: string, body: string, file_name: string}
     */
    private function current(array $current): array
    {
        return [
            'name' => (string) ($current['name'] ?? ''),
            'recipient' => (string) ($current['recipient'] ?? ''),
            'body' => (string) ($current['body'] ?? ''),
            'file_name' => (string) ($current['file_name'] ?? ''),
        ];
    }
}
