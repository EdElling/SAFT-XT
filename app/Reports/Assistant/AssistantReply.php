<?php

declare(strict_types=1);

namespace App\Reports\Assistant;

/** Antwort des Modells: JSON-Text (oder null bei Ablehnung) und Verbrauch. */
final readonly class AssistantReply
{
    public function __construct(
        public ?string $text,
        public ?string $stopReason,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
