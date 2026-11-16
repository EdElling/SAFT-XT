<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\User;
use App\Settings\AppSettings;

/**
 * Einstellungen der KI-Funktionen: Ist in den App-Einstellungen ein Anbieter gewählt, gelten deren Werte (leere Felder
 * fallen auf config/ai.php zurück); sonst gilt config/ai.php (.env). Der API-Schlüssel kommt aus den App-Einstellungen
 * (verschlüsselt) oder ANTHROPIC_API_KEY.
 */
final class AiConfig
{
    public const array PROVIDERS = ['anthropic', 'bedrock', 'vertex'];

    public const array EFFORTS = ['low', 'medium', 'high'];

    public function __construct(private readonly AppSettings $settings) {}

    /** Herkunft der Einstellungen: 'settings' (Oberfläche) oder 'env'. */
    public function source(): string
    {
        return filled($this->settings->ai_provider) ? 'settings' : 'env';
    }

    public function provider(): string
    {
        return (string) ($this->settings->ai_provider ?: config('ai.provider', 'none'));
    }

    public function configured(): bool
    {
        return in_array($this->provider(), self::PROVIDERS, true);
    }

    public function model(): string
    {
        return (string) ($this->value('ai_model') ?? config('ai.model'));
    }

    public function effort(): string
    {
        $effort = (string) ($this->value('ai_effort') ?? config('ai.effort', 'medium'));

        return in_array($effort, self::EFFORTS, true) ? $effort : 'medium';
    }

    public function inferenceGeo(): ?string
    {
        $geo = $this->value('ai_inference_geo') ?? config('ai.inference_geo');

        return filled($geo) ? (string) $geo : null;
    }

    public function apiKey(): ?string
    {
        $key = $this->value('ai_api_key') ?? config('ai.anthropic.api_key');

        return filled($key) ? (string) $key : null;
    }

    public function hasStoredApiKey(): bool
    {
        return filled($this->settings->ai_api_key);
    }

    public function baseUrl(): ?string
    {
        $url = config('ai.anthropic.base_url');

        return filled($url) ? (string) $url : null;
    }

    public function bedrockRegion(): string
    {
        return (string) ($this->value('ai_bedrock_region') ?? config('ai.bedrock.region'));
    }

    public function vertexProject(): string
    {
        return (string) ($this->value('ai_vertex_project') ?? config('ai.vertex.project'));
    }

    public function vertexLocation(): string
    {
        return (string) ($this->value('ai_vertex_location') ?? config('ai.vertex.location'));
    }

    /** Standard-Tageslimit je Person. */
    public function defaultDailyLimit(): int
    {
        return max(0, (int) ($this->settings->ai_daily_limit ?? config('ai.daily_limit', 50)));
    }

    /** Tageslimit dieser Person: eigenes (0 = gesperrt) oder Standard. */
    public function dailyLimit(User $user): int
    {
        return $user->ai_daily_limit !== null ? max(0, (int) $user->ai_daily_limit) : $this->defaultDailyLimit();
    }

    public function providerLabel(): string
    {
        return __('assistant.providers.'.($this->configured() ? $this->provider() : 'none'));
    }

    /** Wert aus den App-Einstellungen, wenn dort ein Anbieter gewählt und das Feld gesetzt ist. */
    private function value(string $property): ?string
    {
        if ($this->source() !== 'settings') {
            return null;
        }

        $value = $this->settings->{$property} ?? null;

        return filled($value) ? (string) $value : null;
    }
}
