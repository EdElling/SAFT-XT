<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Allgemeine Anwendungs-Einstellungen (Dialog "App-Einstellungen").
 * Sammelort für Einstellungen ohne eigenen fachlichen Platz.
 */
class AppSettings extends Settings
{
    /** Anzeigename (Branding). Getrennt von APP_NAME, aus dem Cookie-/Cache-Präfixe stammen. */
    public string $app_name;

    /** Farbschema der Oberfläche für alle (App\Support\ColorScheme). */
    public string $color_scheme;

    /** KI-Assistent für Auswertungen eingeschaltet (zusätzlich AI_PROVIDER und Recht reports.assistant). */
    public bool $ai_assistant_enabled;

    /** Hilfe-Assistent eingeschaltet (zusätzlich KI-Anbieter und Recht help.assistant). */
    public bool $ai_help_enabled;

    /** Hilfe-Assistent kennt das Datenmodell (Tabellen und Felder, die die Person sehen darf) – nie Datensätze. */
    public bool $ai_help_schema;

    /** Werte von Auswahlfeldern an das Modell übertragen (bessere Filter, verrät aber Inhalte der Auswahl). */
    public bool $ai_send_choices;

    /** KI-Anbieter (anthropic, bedrock, vertex, none); null = config/ai.php bzw. .env. Gilt für alle Werte unten. */
    public ?string $ai_provider;

    public ?string $ai_model;

    public ?string $ai_effort;

    public ?string $ai_inference_geo;

    public ?string $ai_bedrock_region;

    public ?string $ai_vertex_project;

    public ?string $ai_vertex_location;

    /** Standard-Tageslimit je Person; einzelne Benutzer können ein eigenes haben (users.ai_daily_limit). */
    public ?int $ai_daily_limit;

    /** API-Schlüssel (Anthropic), verschlüsselt gespeichert; wird nie wieder angezeigt. */
    public ?string $ai_api_key;

    public static function encrypted(): array
    {
        return ['ai_api_key'];
    }

    public static function group(): string
    {
        return 'app';
    }
}
