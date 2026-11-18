<?php

declare(strict_types=1);

return [
    'model' => 'App',
    'plural' => 'Apps',
    'app' => 'App',
    'name' => 'Nom',
    'description' => 'Description',
    'prefix' => 'Préfixe des tables',
    'prefix_help' => 'Proposé lors de la création de tables, p. ex. « lager » → « lager_artikel ». Minuscules et chiffres.',
    'icon' => 'Icône',
    'description_page' => 'Les applications métier regroupent les tables, menus, rapports, rôles et tâches qui vont ensemble. Seules les apps vides peuvent être supprimées.',
    'delete_only_empty' => 'Seules les apps vides peuvent être supprimées.',
    'system' => 'Système',
    'app_help' => 'Appartient à cette application métier ; les liaisons ne sont possibles qu’au sein d’une app.',
    'parts' => [
        'tables' => 'Tables',
        'menus' => 'Menus',
        'reports' => 'Rapports',
        'report_links' => 'Liaisons',
        'roles' => 'Rôles',
        'tasks' => 'Tâches',
    ],
    'not_empty' => 'L’app « :name » n’est pas vide. Déplacez ou supprimez d’abord ses tables, menus, rapports, rôles et tâches.',
    'errors' => [
        'unknown' => 'Cette app n’existe pas.',
        'required' => 'Veuillez choisir une app.',
        'cross_app_relation' => 'Les liaisons ne sont possibles qu’avec des tables de la même app – « :table » appartient à l’app « :app ».',
        'cross_app_link' => 'Les liaisons de tables ne sont possibles qu’entre tables de la même app.',
    ],
];
