<?php

declare(strict_types=1);

namespace App\Apps\Designer;

use App\Ai\AiConversation;
use App\Ai\AiUsage;
use App\DynamicData\Assistant\TableProposalSchema;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Übersetzt einen Entwurf des KI-App-Designers vor dem Erzeugen (B-107, Etappe 7): Im Gespräch entstehen Bezeichnungen
 * nur in der Sprache der Person (deutlich kürzere Antworten); hier gehen die fehlenden Sprachen einmal nach – App,
 * Tabellen, Felder, Auswahlwerte, Menüs. An die KI geht nur eine Liste der Texte (gleiche Texte einmal), in Teilen.
 * Scheitert es, bleibt der Entwurf unübersetzt und ein Hinweis nennt den Grund – erzeugt wird trotzdem.
 */
final class DraftTranslator
{
    /** Texte je Aufruf (damit keine Antwort an die Längengrenze stösst); in Tests kleiner. */
    public static int $chunk = 120;

    private const string INSTRUCTIONS = <<<'TXT'
        You translate the user interface labels of a business application (table names, field names, choices, menu
        entries, short descriptions). Respond only with JSON that matches the given schema: for every text its id and the
        translation into each target language. Keep the translations as short as the source, use the usual terms of
        business software in each language (e.g. "MWST" → "VAT", "TVA", "IVA"; "Beleg" → "Receipt", "Justificatif",
        "Giustificativo"), keep units, abbreviations in brackets and proper names, and never leave a translation empty.
        TXT;

    public function __construct(private readonly AssistantClient $client) {}

    /**
     * @param  array<string, mixed>  $definition  App-Definition saft-app/1
     * @return array{definition: array<string, mixed>, warning: string|null}
     */
    public function translate(User $user, array $definition): array
    {
        $locales = array_keys((array) config('locales.available'));
        $texts = [];
        $this->walk($definition, $locales, static function (array $value, array $missing) use (&$texts): array {
            $source = self::source($value);

            if ($source !== null) {
                $texts[$source] = array_values(array_unique([...($texts[$source] ?? []), ...$missing]));
            }

            return $value;
        });

        if ($texts === []) {
            return ['definition' => $definition, 'warning' => null];
        }

        $translations = [];
        $warning = null;

        foreach (array_chunk(array_keys($texts), self::$chunk) as $chunk) {
            try {
                $translations = [...$translations, ...$this->ask($user, $chunk, $locales)];
            } catch (Throwable $e) {
                Log::warning('App-Designer: Übersetzung fehlgeschlagen', ['error' => $e->getMessage()]);
                $warning = __('app_designer.translation_failed', ['error' => $e->getMessage()]);

                break;
            }
        }

        $definition = $this->walk($definition, $locales, static function (array $value, array $missing) use ($translations): array {
            $source = self::source($value);

            foreach ($missing as $locale) {
                if ($source !== null && filled($translations[$source][$locale] ?? null)) {
                    $value[$locale] = (string) $translations[$source][$locale];
                }
            }

            return $value;
        });

        return ['definition' => $definition, 'warning' => $warning];
    }

    /**
     * Eine Liste von Texten übersetzen.
     *
     * @param  list<string>  $texts
     * @param  list<string>  $locales
     * @return array<string, array<string, string>> Text => Sprache => Übersetzung
     */
    private function ask(User $user, array $texts, array $locales): array
    {
        $ids = [];

        foreach (array_values($texts) as $i => $text) {
            $ids['t'.($i + 1)] = $text;
        }

        $schema = TableProposalSchema::object(['translations' => ['type' => 'array', 'items' => TableProposalSchema::object([
            'id' => ['type' => 'string'],
            ...array_fill_keys($locales, ['type' => 'string']),
        ])]]);
        $request = ['role' => 'user', 'content' => 'Target languages: '.implode(', ', $locales).". Fill the language a text is already written in with the text itself.\nTexts: ".json_encode($ids, JSON_UNESCAPED_UNICODE)];
        $conversation = new AiConversation($this->client);
        $answer = $conversation->ask([['type' => 'text', 'text' => self::INSTRUCTIONS]], [$request], $schema, static function (array $proposal) use ($ids, $locales): array {
            $given = collect((array) ($proposal['translations'] ?? []))->filter(static fn (mixed $row): bool => is_array($row))->keyBy('id');
            $missing = array_values(array_filter(array_keys($ids), static fn (string $id): bool => ! isset($given[$id])
                || collect($locales)->contains(static fn (string $l): bool => blank($given[$id][$l] ?? null))));

            return $missing === [] ? [] : ['Missing or empty translations for: '.implode(', ', array_slice($missing, 0, 20))];
        });

        AiUsage::log($user, 'apps', __('app_designer.translation_log', ['count' => count($ids)]), $answer['status'], $conversation->usage, $conversation->model(), $answer['status'] === AiConversation::OK ? null : implode('; ', $answer['errors']));

        if ($answer['status'] !== AiConversation::OK) {
            throw new \RuntimeException($answer['errors'] !== [] ? implode('; ', $answer['errors']) : __('assistant.errors.refused'));
        }

        $result = [];

        foreach ((array) $answer['proposal']['translations'] as $row) {
            if (is_array($row) && isset($ids[$row['id'] ?? ''])) {
                $result[$ids[$row['id']]] = array_map('strval', array_intersect_key($row, array_flip($locales)));
            }
        }

        return $result;
    }

    /**
     * Alle übersetzbaren Texte der Definition (Bezeichnungen je Sprache), denen Sprachen fehlen.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $locales
     * @param  callable(array<string, string>, list<string>): array<string, string>  $visit
     * @return array<string, mixed>
     */
    private function walk(array $definition, array $locales, callable $visit): array
    {
        $apply = static function (mixed $value) use ($locales, $visit): mixed {
            if (! is_array($value) || array_diff(array_keys($value), $locales) !== [] || self::source($value) === null) {
                return $value;
            }

            $missing = array_values(array_filter($locales, static fn (string $l): bool => blank($value[$l] ?? null)));

            return $missing === [] ? $value : $visit($value, $missing);
        };

        foreach (['name', 'description'] as $key) {
            $definition['app'][$key] = $apply($definition['app'][$key] ?? null);
        }

        foreach ((array) $definition['tables'] as $t => $table) {
            foreach (['label', 'description'] as $key) {
                $definition['tables'][$t][$key] = $apply($table[$key] ?? null);
            }

            foreach ((array) $table['fields'] as $f => $field) {
                $definition['tables'][$t]['fields'][$f]['label'] = $apply($field['label'] ?? null);

                foreach ((array) ($field['options']['options'] ?? []) as $o => $option) {
                    if (is_array($option)) {
                        $definition['tables'][$t]['fields'][$f]['options']['options'][$o]['label'] = $apply($option['label'] ?? null);
                    }
                }
            }
        }

        foreach ((array) $definition['menus'] as $m => $menu) {
            $definition['menus'][$m]['label'] = $apply($menu['label'] ?? null);
        }

        return $definition;
    }

    /** Der vorhandene Text (erste nicht leere Sprache), null wenn keiner. */
    private static function source(array $value): ?string
    {
        foreach ($value as $text) {
            if (filled($text)) {
                return trim((string) $text);
            }
        }

        return null;
    }
}
