<?php

declare(strict_types=1);

/** KI-App-Designer (B-107, Etappe 7a). */
return [
    'untitled' => 'Nouveau brouillon',
    'errors' => [
        'app_name' => 'L’app a besoin d’un nom.',
        'prefix' => 'Le préfixe « :prefix » n’est pas valable : 1 à 20 lettres minuscules et chiffres, commençant par une lettre.',
        'prefix_taken' => 'Le préfixe « :prefix » est déjà utilisé par une autre app.',
        'no_tables' => 'Le brouillon n’a pas de tables.',
        'table_prefix' => 'La table « :table » doit commencer par le préfixe « :prefix ».',
        'duplicate_table' => 'La table « :table » apparaît deux fois.',
        'menu_label' => 'Un groupe de menu n’a pas de libellé.',
        'menu_table' => 'Le groupe de menu cite la table « :table », absente du brouillon.',
        'menu_twice' => 'La table « :table » figure dans plus d’un groupe de menu.',
        'invalid' => 'Aucun brouillon valable : :errors',
        'nothing_to_create' => 'Il n’y a pas encore de brouillon à partir duquel créer une app.',
        'already_created' => 'Une app a déjà été créée à partir de ce brouillon.',
    ],
];
