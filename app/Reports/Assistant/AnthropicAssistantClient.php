<?php

declare(strict_types=1);

namespace App\Reports\Assistant;

use Anthropic\Bedrock\MantleClient;
use Anthropic\Client;
use Anthropic\Vertex;
use App\Ai\AiConfig;
use App\Exceptions\BusinessRuleException;

/**
 * Claude über das offizielle PHP-SDK – direkt bei Anthropic, über Amazon Bedrock oder Google Vertex AI
 * (config/ai.php). Strukturierte Ausgabe per JSON-Schema; das Datenmodell im System-Prompt wird zwischengespeichert.
 */
final class AnthropicAssistantClient implements AssistantClient
{
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(private readonly AiConfig $config) {}

    public function model(): string
    {
        return $this->config->model();
    }

    public function complete(array $system, array $messages, array $schema): AssistantReply
    {
        $blocks = array_map(static fn (array $b): array => array_filter([
            'type' => 'text',
            'text' => $b['text'],
            'cacheControl' => ($b['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
        ]), $system);
        $common = [
            'model' => $this->model(),
            'maxTokens' => (int) config('ai.max_tokens', 16000),
            'system' => $blocks,
            'messages' => $messages,
            'outputConfig' => [
                'effort' => $this->config->effort(),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'requestOptions' => ['timeout' => (float) config('ai.timeout', 120)],
        ];

        $message = match ($this->config->provider()) {
            // Direkt: bei einer Ablehnung aus Sicherheitsgründen übernimmt serverseitig ein Ersatzmodell.
            'anthropic' => self::anthropic($this->config)->beta->messages->create(
                ...$common,
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
                inferenceGeo: $this->config->inferenceGeo(),
            ),
            'bedrock' => self::bedrock($this->config)->messages->create(...$common),
            'vertex' => self::vertex($this->config)->messages->create(...$common),
            default => throw new BusinessRuleException(__('assistant.errors.not_configured')),
        };

        $text = null;

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }

        return new AssistantReply(
            text: $text,
            stopReason: $message->stopReason,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
        );
    }

    /** Schlüssel ausdrücklich übergeben: bei zwischengespeicherter Konfiguration liest das SDK die .env nicht. */
    public static function anthropic(AiConfig $config, ?string $apiKey = null): Client
    {
        return new Client(apiKey: $apiKey ?? $config->apiKey(), baseUrl: $config->baseUrl());
    }

    public static function bedrock(AiConfig $config): MantleClient
    {
        return new MantleClient(awsRegion: $config->bedrockRegion());
    }

    public static function vertex(AiConfig $config): Vertex\Client
    {
        return Vertex\Client::fromEnvironment(location: $config->vertexLocation(), projectId: $config->vertexProject());
    }
}
