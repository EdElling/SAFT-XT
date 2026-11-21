<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Reports\Assistant\AssistantClient;
use App\Reports\Assistant\AssistantReply;
use RuntimeException;

/** Modell-Attrappe: gibt vorbereitete Antworten zurück und merkt sich jede Anfrage. */
final class FakeAssistantClient implements AssistantClient
{
    /** @var list<AssistantReply> */
    private array $replies = [];

    /** @var list<array{system: array<int, mixed>, messages: array<int, mixed>, schema: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>|AssistantReply|\Closure  $reply  Vorschlag als Array, fertige Antwort oder eine Funktion,
     *                                                               die aus der Anfrage ($messages) den Vorschlag berechnet
     */
    public function push(array|AssistantReply|\Closure $reply): self
    {
        $this->replies[] = $reply instanceof AssistantReply || $reply instanceof \Closure
            ? $reply
            : new AssistantReply(json_encode($reply, JSON_THROW_ON_ERROR), 'end_turn', inputTokens: 1200, outputTokens: 150, cacheReadTokens: 800);

        return $this;
    }

    public function complete(array $system, array $messages, array $schema, bool $strict = true): AssistantReply
    {
        $this->requests[] = compact('system', 'messages', 'schema', 'strict');

        $reply = array_shift($this->replies) ?? throw new RuntimeException('Keine Antwort vorbereitet.');

        return $reply instanceof \Closure
            ? new AssistantReply(json_encode($reply($messages), JSON_THROW_ON_ERROR), 'end_turn', inputTokens: 1200, outputTokens: 150)
            : $reply;
    }

    public function model(): string
    {
        return 'fake-model';
    }

    /** Alles, was an das Modell ging, als Text. */
    public function sentText(): string
    {
        return json_encode($this->requests, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
