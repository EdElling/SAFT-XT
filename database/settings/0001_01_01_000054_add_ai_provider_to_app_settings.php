<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * KI-Anbieter in den App-Einstellungen statt nur in der .env: leer = Wert aus config/ai.php (.env).
 * Der API-Schlüssel wird verschlüsselt gespeichert (APP_KEY).
 */
return new class extends SettingsMigration
{
    private const array KEYS = ['ai_provider', 'ai_model', 'ai_effort', 'ai_inference_geo', 'ai_bedrock_region', 'ai_vertex_project', 'ai_vertex_location', 'ai_daily_limit'];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            $this->migrator->add('app.'.$key, null);
        }

        $this->migrator->addEncrypted('app.ai_api_key', null);
    }

    public function down(): void
    {
        foreach ([...self::KEYS, 'ai_api_key'] as $key) {
            $this->migrator->delete('app.'.$key);
        }
    }
};
