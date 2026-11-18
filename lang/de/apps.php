<?php

declare(strict_types=1);

return [
    'model' => 'App',
    'plural' => 'Apps',
    'app' => 'App',
    'name' => 'Name',
    'description' => 'Beschreibung',
    'prefix' => 'Präfix für Tabellen',
    'prefix_help' => 'Wird beim Anlegen von Tabellen vorgeschlagen, z. B. „lager“ → „lager_artikel“. Kleinbuchstaben und Ziffern.',
    'icon' => 'Symbol',
    'description_page' => 'Fachanwendungen fassen Tabellen, Menüs, Auswertungen, Rollen und Aufgaben zusammen, die zusammengehören. Nur leere Apps lassen sich löschen.',
    'delete_only_empty' => 'Nur leere Apps lassen sich löschen.',
    'system' => 'System',
    'app_help' => 'Gehört zu dieser Fachanwendung; Verknüpfungen sind nur innerhalb einer App möglich.',
    'parts' => [
        'tables' => 'Tabellen',
        'menus' => 'Menüs',
        'reports' => 'Auswertungen',
        'report_links' => 'Verknüpfungen',
        'roles' => 'Rollen',
        'tasks' => 'Aufgaben',
    ],
    'not_empty' => 'Die App „:name“ ist nicht leer. Verschieben oder löschen Sie zuerst ihre Tabellen, Menüs, Auswertungen, Rollen und Aufgaben.',
    'errors' => [
        'unknown' => 'Diese App gibt es nicht.',
        'required' => 'Bitte eine App wählen.',
        'cross_app_relation' => 'Verknüpfungen sind nur zu Tabellen derselben App möglich – „:table“ gehört zur App „:app“.',
        'cross_app_link' => 'Tabellenverknüpfungen sind nur zwischen Tabellen derselben App möglich.',
    ],
];
