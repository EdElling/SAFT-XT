<?php

declare(strict_types=1);

/*
| KI-Assistent für Auswertungen (Etappe 5). Standardmäßig aus: AI_PROVIDER setzen, Zugangsdaten eintragen und in den
| App-Einstellungen einschalten. Übertragen wird nur das Datenmodell (Tabellen und Felder, die die Person auswerten
| darf) und der eingegebene Text – nie Datensätze. Siehe Systemadministration, Kapitel 5 „KI-Funktionen“.
*/
return [
    // none | anthropic | bedrock | vertex
    'provider' => env('AI_PROVIDER', 'none'),

    // Modell-ID; bei Amazon Bedrock mit Präfix, z. B. anthropic.claude-opus-5-5
    'model' => env('AI_MODEL', 'claude-opus-5-5'),

    // Denktiefe: low | medium | high
    'effort' => env('AI_EFFORT', 'medium'),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),

    // Sekunden je Anfrage an das Modell
    'timeout' => (int) env('AI_TIMEOUT', 120),

    // KI-App-Designer: Eine ganze App ist eine lange Antwort – höhere Grenzen nur dort (Token je Antwort, Sekunden je
    // Anfrage). Mehr Token erlauben grössere Apps, dauern aber länger und kosten mehr.
    'designer_max_tokens' => (int) env('AI_DESIGNER_MAX_TOKENS', 32000),
    'designer_timeout' => (int) env('AI_DESIGNER_TIMEOUT', 900),

    // Anfragen je Person und Tag
    'daily_limit' => (int) env('AI_DAILY_LIMIT', 50),

    // Anthropic direkt: Region der Verarbeitung (leer = Vorgabe des Workspace)
    'inference_geo' => env('AI_INFERENCE_GEO'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Nur für Proxys/Gateways; leer = api.anthropic.com
        'base_url' => env('AI_BASE_URL'),
    ],

    // Amazon Bedrock: AWS-Zugangsdaten wie üblich aus der Umgebung (AWS_ACCESS_KEY_ID …)
    'bedrock' => [
        'region' => env('AI_AWS_REGION'),
    ],

    // Google Vertex AI: Anmeldung über Application Default Credentials; braucht composer require google/auth
    'vertex' => [
        'project' => env('AI_VERTEX_PROJECT'),
        'location' => env('AI_VERTEX_LOCATION'),
    ],
];
