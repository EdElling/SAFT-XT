<?php

declare(strict_types=1);

namespace App\Ai;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Reports\Assistant\AnthropicAssistantClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * „Verbindung testen“ in den App-Einstellungen. Anthropic direkt: Abfrage des Modells über die Modell-API (kostenlos)
 * und Liste der verfügbaren Modelle; Bedrock/Vertex: eine Mini-Anfrage (wenige Tokens).
 */
final class AiConnectionTester
{
    /** @return array{ok: bool, message: string, models: list<string>} */
    public function test(AiConfig $config, ?string $apiKey = null): array
    {
        if (! $config->configured()) {
            return ['ok' => false, 'message' => __('assistant.settings.test.no_provider'), 'models' => []];
        }

        try {
            if ($config->provider() === 'anthropic') {
                $client = AnthropicAssistantClient::anthropic($config, $apiKey);
                $model = $client->models->retrieve(modelID: $config->model());
                $models = array_values(array_map(static fn ($m): string => $m->id, $client->models->list()->getItems()));

                return ['ok' => true, 'message' => __('assistant.settings.test.ok_model', ['model' => $model->displayName.' ('.$model->id.')']), 'models' => $models];
            }

            $client = $config->provider() === 'bedrock' ? AnthropicAssistantClient::bedrock($config) : AnthropicAssistantClient::vertex($config);
            $client->messages->create(model: $config->model(), maxTokens: 16, messages: [['role' => 'user', 'content' => 'ping']]);

            return ['ok' => true, 'message' => __('assistant.settings.test.ok_reply', ['model' => $config->model()]), 'models' => []];
        } catch (AuthenticationException|PermissionDeniedException) {
            return ['ok' => false, 'message' => __('assistant.settings.test.auth'), 'models' => []];
        } catch (NotFoundException) {
            return ['ok' => false, 'message' => __('assistant.settings.test.model', ['model' => $config->model()]), 'models' => []];
        } catch (APIConnectionException) {
            return ['ok' => false, 'message' => __('assistant.settings.test.connection'), 'models' => []];
        } catch (APIStatusException $e) {
            return ['ok' => false, 'message' => __('assistant.settings.test.status', ['type' => (string) ($e->type?->value ?? $e->getCode())]), 'models' => []];
        } catch (Throwable $e) {
            Log::warning('KI-Verbindungstest fehlgeschlagen', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('assistant.settings.test.failed'), 'models' => []];
        }
    }
}
