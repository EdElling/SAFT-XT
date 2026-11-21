<?php

declare(strict_types=1);

namespace App\Ai;

use App\Reports\Assistant\AssistantClient;
use JsonException;

/**
 * Eine Anfrage an das Modell mit strenger JSON-Antwort und höchstens einer Korrekturrunde: Die Prüfung des Aufrufers
 * liefert Fehler, die einmal mit der Bitte um Korrektur zurückgehen.
 */
final class AiConversation
{
    public const string OK = 'ok';

    public const string QUESTION = 'question';

    public const string REFUSED = 'refused';

    public const string INVALID = 'invalid';

    /** Antwort an der Längengrenze abgebrochen: keine Korrekturrunde (sie würde wieder zu lang). */
    public const string TRUNCATED = 'truncated';

    /** @var array{in: int, out: int, cache: int} */
    public array $usage = ['in' => 0, 'out' => 0, 'cache' => 0];

    public function __construct(private readonly AssistantClient $client) {}

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * @param  list<array<string, mixed>>  $system
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @param  callable(array<string, mixed>): list<string>  $check  Fehler des Vorschlags (leer = gültig)
     * @return array{status: string, proposal: array<string, mixed>, history: list<array{role: string, content: string}>, question: ?string, errors: list<string>}
     */
    public function ask(array $system, array $messages, array $schema, callable $check, bool $strict = true): array
    {
        $errors = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $this->client->complete($system, $messages, $schema, $strict);
            $this->usage['in'] += $reply->inputTokens + $reply->cacheReadTokens + $reply->cacheWriteTokens;
            $this->usage['out'] += $reply->outputTokens;
            $this->usage['cache'] += $reply->cacheReadTokens;

            if ($reply->refused() || $reply->text === null) {
                return ['status' => self::REFUSED, 'proposal' => [], 'history' => $messages, 'question' => null, 'errors' => []];
            }

            if ($reply->truncated()) {
                return ['status' => self::TRUNCATED, 'proposal' => [], 'history' => $messages, 'question' => null, 'errors' => [__('assistant.errors.truncated')]];
            }

            try {
                $proposal = (array) json_decode($reply->text, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $proposal = [];
            }

            $history = [...$messages, ['role' => 'assistant', 'content' => $reply->text]];

            if (($proposal['kind'] ?? null) === 'question' && filled($proposal['question'] ?? null)) {
                return ['status' => self::QUESTION, 'proposal' => $proposal, 'history' => $history, 'question' => (string) $proposal['question'], 'errors' => []];
            }

            $errors = $check($proposal);

            if ($errors === []) {
                return ['status' => self::OK, 'proposal' => $proposal, 'history' => $history, 'question' => null, 'errors' => []];
            }

            $messages = [...$history, ['role' => 'user', 'content' => "The proposal is not valid:\n- ".implode("\n- ", $errors)."\nCorrect it."]];
        }

        return ['status' => self::INVALID, 'proposal' => [], 'history' => $messages, 'question' => null, 'errors' => $errors];
    }
}
