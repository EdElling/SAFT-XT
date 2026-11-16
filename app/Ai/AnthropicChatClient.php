<?php

declare(strict_types=1);

namespace App\Ai;

use App\Exceptions\BusinessRuleException;
use App\Reports\Assistant\AnthropicAssistantClient;

/**
 * ChatClient über Claude – direkt bei Anthropic, über Amazon Bedrock oder Google Vertex AI, wie in den
 * App-Einstellungen bzw. config/ai.php eingestellt. Systemteile mit „cache“ werden zwischengespeichert (Handbücher:
 * Folgefragen kosten nur einen Bruchteil).
 */
final class AnthropicChatClient implements ChatClient
{
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(private readonly AiConfig $config) {}

    public function model(): string
    {
        return $this->config->model();
    }

    public function chat(array $system, array $messages): ChatReply
    {
        $common = [
            'model' => $this->model(),
            'maxTokens' => (int) config('ai.max_tokens', 16000),
            'system' => array_map(static fn (array $b): array => array_filter([
                'type' => 'text',
                'text' => $b['text'],
                'cacheControl' => ($b['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
            ]), $system),
            'messages' => $messages,
            'outputConfig' => ['effort' => $this->config->effort()],
            'requestOptions' => ['timeout' => (float) config('ai.timeout', 120)],
        ];

        $message = match ($this->config->provider()) {
            'anthropic' => AnthropicAssistantClient::anthropic($this->config)->beta->messages->create(
                ...$common,
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
                inferenceGeo: $this->config->inferenceGeo(),
            ),
            'bedrock' => AnthropicAssistantClient::bedrock($this->config)->messages->create(...$common),
            'vertex' => AnthropicAssistantClient::vertex($this->config)->messages->create(...$common),
            default => throw new BusinessRuleException(__('assistant.errors.not_configured')),
        };

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new ChatReply(
            text: $text === '' ? null : $text,
            stopReason: $message->stopReason,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
        );
    }
}
