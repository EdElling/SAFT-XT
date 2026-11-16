<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Ai\ChatClient;
use App\Ai\ChatReply;
use RuntimeException;

/** ChatClient für Tests: liefert vorbereitete Antworten und merkt sich, was gesendet wurde. */
final class FakeChatClient implements ChatClient
{
    /** @var list<array{system: list<array{text: string, cache?: bool}>, messages: list<array{role: string, content: string}>}> */
    public array $calls = [];

    /** @var list<ChatReply|RuntimeException> */
    private array $replies = [];

    public function push(string $text, ?string $stopReason = 'end_turn'): self
    {
        $this->replies[] = new ChatReply($text, $stopReason, 1200, 150, 1000);

        return $this;
    }

    public function fail(): self
    {
        $this->replies[] = new RuntimeException('Netzwerkfehler');

        return $this;
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function chat(array $system, array $messages): ChatReply
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages];
        $reply = array_shift($this->replies) ?? new ChatReply('Antwort', 'end_turn', 10, 5);

        if ($reply instanceof RuntimeException) {
            throw $reply;
        }

        return $reply;
    }
}
