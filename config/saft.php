<?php

declare(strict_types=1);

return [
    // Version von SAFT (bei jedem Release anheben); erscheint unter „Dokumentation › Über SAFT“.
    'version' => '0.10.0',

    // Status des Releases (wie auf GitHub, z. B. „pre-release“); leer, sobald es ein reguläres Release ist.
    'release_status' => 'pre-release',

    // Version der Programmierschnittstelle für Plugins (App\Plugins\Api, siehe Entwicklerhandbuch), unabhängig von der
    // SAFT-Version: Minor = nur Ergänzungen, Major = Breaking Changes. Jede bereitgestellte Major-Version einmal.
    'api_versions' => ['1.11'],

    // HSTS: Browser rufen SAFT ein Jahr lang nur noch über HTTPS auf (nur gesendet, wenn die Anfrage über HTTPS kam).
    'hsts' => (bool) env('SECURITY_HSTS', true),

    // Neue Passwörter mit Datenlecks abgleichen (Have I Been Pwned; nur die ersten 5 Zeichen des SHA-1-Hashes verlassen
    // den Server). Abschalten für Server ohne Internetzugang und in Tests.
    'password_check_leaked' => (bool) env('PASSWORD_CHECK_LEAKED', true),

    // Pflicht zur Zwei-Faktor-Anmeldung durchsetzen (Einstellung unter Administration › Authentifizierung). Aus nur in
    // Tests; wer schon einen zweiten Faktor eingerichtet hat, wird bei der Anmeldung trotzdem danach gefragt.
    'two_factor_enforce' => (bool) env('TWO_FACTOR_ENFORCE', true),

    // Geplante Aufgabe „Demo-Daten zurücksetzen“ erlauben – nur für öffentliche Demo- und Schulungssysteme.
    'demo_reset' => (bool) env('DEMO_RESET', false),

    // Antwortzeit im Header „Server-Timing“ aufschlüsseln (Start, Datenbank, Anwendung) – zum Messen auf Hosting ohne
    // Server-Logs; jeder Besucher sieht die Zeiten, darum nach dem Messen wieder ausschalten.
    'server_timing' => (bool) env('SERVER_TIMING', false),
];
