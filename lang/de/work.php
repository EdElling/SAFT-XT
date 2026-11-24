<?php

declare(strict_types=1);

return [
    'model' => 'Arbeit',
    'plural' => 'Arbeiten',
    'status' => [
        'queued' => 'wartet',
        'running' => 'läuft',
        'done' => 'fertig',
        'failed' => 'fehlgeschlagen',
        'cancelled' => 'abgebrochen',
    ],
    'types' => [
        'recompute' => 'Berechnete Felder neu berechnen',
        'export' => 'Exportieren',
        'documents' => 'Dokumente erstellen',
        'record_import' => 'Daten importieren',
    ],
    'export' => [
        'done' => '1 Zeile exportiert.|:count Zeilen exportiert.',
        'table_missing' => 'Die Tabelle gibt es nicht mehr.',
    ],
    'import' => [
        'table_missing' => 'Die Tabelle gibt es nicht mehr.',
        'file_missing' => 'Die hochgeladene Datei liegt nicht mehr vor; bitte den Import neu starten.',
    ],
    'recompute' => [
        'all_tables' => 'alle Tabellen mit berechneten Feldern',
    ],
    'cancelled' => 'Abgebrochen.',
    'aborted_repeatedly' => 'Mehrmals ohne Abschluss abgebrochen (z. B. Zeitlimit des Servers überschritten). Die Arbeit wurde beendet.',
    'failed_generic' => 'Die Arbeit ist fehlgeschlagen (Referenz :reference). Details stehen im Systemprotokoll.',
    'plugin_inactive' => 'Das Plugin „:plugin“ ruht; die Arbeit wurde nicht ausgeführt.',
    'started' => 'Arbeit gestartet (:id); der Scheduler führt sie im Hintergrund aus.',
    'my_work' => 'Meine Arbeiten',
    'empty' => 'Keine Arbeiten',
    'system' => 'System',
    'tabs' => [
        'all' => 'Alle',
        'mine' => 'Meine',
    ],
    'sections' => [
        'details' => 'Angaben',
    ],
    'fields' => [
        'type' => 'Art',
        'description' => 'Beschreibung',
        'user' => 'Gestartet von',
        'app' => 'App',
        'status' => 'Status',
        'progress' => 'Fortschritt',
        'created' => 'Gestartet',
        'finished' => 'Beendet',
        'duration' => 'Dauer',
        'message' => 'Meldung',
    ],
    'actions' => [
        'download' => 'Ergebnis herunterladen',
        'cancel' => 'Abbrechen',
        'cancel_heading' => 'Arbeit abbrechen?',
        'cancel_requested' => 'Die Arbeit wird abgebrochen.',
        'cancelling' => 'Wird abgebrochen …',
    ],
    'progress' => [
        'driving' => 'Läuft, solange diese Seite offen ist. Wenn Sie sie schliessen, wird im Hintergrund weitergearbeitet, und Sie erhalten am Ende eine Benachrichtigung.',
        'background' => 'Läuft im Hintergrund.',
        'expires' => 'verfügbar bis :time',
    ],
    'notifications' => [
        'done' => '„:work“ ist fertig',
        'failed' => '„:work“ ist fehlgeschlagen',
        'open' => 'Öffnen',
    ],
    'widget' => [
        'stuck' => '1 Arbeit ohne Fortschritt seit über :minutes Minuten (läuft der Scheduler?)|:count Arbeiten ohne Fortschritt seit über :minutes Minuten (läuft der Scheduler?)',
        'failed' => '1 Arbeit in den letzten 24 Stunden fehlgeschlagen|:count Arbeiten in den letzten 24 Stunden fehlgeschlagen',
    ],
];
