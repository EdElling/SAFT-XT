<?php

declare(strict_types=1);

return [
    'title' => 'Datenstände',
    'description' => 'Unveränderliche Kopien dieser Tabelle für Auswertungen. Auswertungen rechnen immer mit dem neuesten vollständigen Stand. Löschungen in der Quelle wirken erst mit dem nächsten Stand; ältere Stände entfernt die Aufbewahrung.',
    'refresh' => 'Jetzt aktualisieren',
    'refreshed' => 'Datenstand gezogen: :rows Zeilen',
    'retention' => 'Aufbewahrung',
    'keep' => 'Letzte Stände behalten',
    'keep_help' => 'Mindestens 1; der neueste Stand bleibt immer.',
    'max_days' => 'Höchstens Tage',
    'max_days_help' => 'Leer = ohne Frist.',
    'retention_saved' => 'Aufbewahrung gespeichert',
    'delete' => 'Löschen',
    'delete_all' => 'Alle Stände löschen',
    'delete_all_description' => 'Alle Datenstände dieser Tabelle werden gelöscht. Auswertungen darauf laufen erst nach dem nächsten Aktualisieren wieder.',
    'deleted' => 'Datenstand gelöscht',
    'deleted_all' => 'Alle Datenstände gelöscht',
    'time' => 'Zeitpunkt',
    'rows' => 'Zeilen',
    'status' => 'Status',
    'by' => 'Ausgelöst von',
    'automatic' => 'automatisch',
    'message' => 'Meldung',
    'statuses' => [
        'running' => 'läuft',
        'complete' => 'vollständig',
        'failed' => 'fehlgeschlagen',
    ],
    'none' => 'Noch kein Datenstand',
    'as_of' => 'Datenstand: :table (:connection) vom :time',
    'errors' => [
        'failed' => 'Der Datenstand konnte nicht gezogen werden; der vorherige bleibt gültig. Details im Protokoll der Anwendung.',
        'too_large' => 'Die Tabelle hat mehr als :max Zeilen – zu gross für einen Datenstand.',
        'not_external' => 'Datenstände gibt es nur für angebundene Tabellen.',
        'busy' => 'Diese Tabelle wird gerade aktualisiert. Bitte später noch einmal versuchen.',
        'aborted' => 'Abgebrochen (der Prozess wurde unterbrochen).',
    ],
];
