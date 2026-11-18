<?php

declare(strict_types=1);

return [
    'model' => 'App',
    'plural' => 'App',
    'app' => 'App',
    'name' => 'Nome',
    'description' => 'Descrizione',
    'prefix' => 'Prefisso delle tabelle',
    'prefix_help' => 'Proposto alla creazione di tabelle, ad es. «lager» → «lager_artikel». Lettere minuscole e cifre.',
    'icon' => 'Icona',
    'description_page' => 'Le applicazioni specialistiche raggruppano tabelle, menu, report, ruoli e attività che vanno insieme. Si possono eliminare solo app vuote.',
    'delete_only_empty' => 'Si possono eliminare solo app vuote.',
    'system' => 'Sistema',
    'app_help' => 'Appartiene a questa applicazione specialistica; i collegamenti sono possibili solo all’interno di un’app.',
    'parts' => [
        'tables' => 'Tabelle',
        'menus' => 'Menu',
        'reports' => 'Report',
        'report_links' => 'Collegamenti',
        'roles' => 'Ruoli',
        'tasks' => 'Attività',
    ],
    'not_empty' => 'L’app «:name» non è vuota. Spostate o eliminate prima le sue tabelle, menu, report, ruoli e attività.',
    'errors' => [
        'unknown' => 'Questa app non esiste.',
        'required' => 'Scegliete un’app.',
        'cross_app_relation' => 'I collegamenti sono possibili solo con tabelle della stessa app – «:table» appartiene all’app «:app».',
        'cross_app_link' => 'I collegamenti tra tabelle sono possibili solo tra tabelle della stessa app.',
    ],
];
