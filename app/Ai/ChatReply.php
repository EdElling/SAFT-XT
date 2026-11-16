<?php

declare(strict_types=1);

namespace App\Ai;

/** Antwort eines ChatClient: Text und Verbrauch (Tokens). */
final readonly class ChatReply
{
    public const string REFUSAL = 'refusal';

    public function __construct(
        public ?string $text,
        public ?string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    /** @return array{in: int, out: int, cache: int} für AiUsage::log() */
    public function usage(): array
    {
        return ['in' => $this->inputTokens, 'out' => $this->outputTokens, 'cache' => $this->cacheReadTokens];
    }
}
