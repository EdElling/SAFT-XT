<?php

declare(strict_types=1);

return [
    // Version von SAFT (bei jedem Release anheben); erscheint unter „Dokumentation › Über SAFT“.
    'version' => '0.1.0',

    // Version der Programmierschnittstelle für Plugins (App\Plugins\Api, siehe Entwicklerhandbuch), unabhängig von der
    // SAFT-Version: Minor = nur Ergänzungen, Major = Breaking Changes. Jede bereitgestellte Major-Version einmal.
    'api_versions' => ['1.6'],

    // HSTS: Browser rufen SAFT ein Jahr lang nur noch über HTTPS auf (nur gesendet, wenn die Anfrage über HTTPS kam).
    'hsts' => (bool) env('SECURITY_HSTS', true),

    // Neue Passwörter mit Datenlecks abgleichen (Have I Been Pwned; nur die ersten 5 Zeichen des SHA-1-Hashes verlassen
    // den Server). Abschalten für Server ohne Internetzugang und in Tests.
    'password_check_leaked' => (bool) env('PASSWORD_CHECK_LEAKED', true),
];
