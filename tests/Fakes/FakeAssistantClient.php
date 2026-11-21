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

    /** @param  array<string, mixed>|AssistantReply  $reply  Vorschlag als Array oder fertige Antwort */
    public function push(array|AssistantReply $reply): self
    {
        $this->replies[] = $reply instanceof AssistantReply
            ? $reply
            : new AssistantReply(json_encode($reply, JSON_THROW_ON_ERROR), 'end_turn', inputTokens: 1200, outputTokens: 150, cacheReadTokens: 800);

        return $this;
    }

    public function complete(array $system, array $messages, array $schema, bool $strict = true): AssistantReply
    {
        $this->requests[] = compact('system', 'messages', 'schema', 'strict');

        return array_shift($this->replies) ?? throw new RuntimeException('Keine Antwort vorbereitet.');
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
