<?php

declare(strict_types=1);

// Ergänzt Texte, die in Filaments eigener Übersetzung fehlen.
return [
    'actions' => [
        'move_record_down' => [
            'label' => 'Descendre :label',
        ],
        'move_record_up' => [
            'label' => 'Monter :label',
        ],
        'reorder_record' => [
            'label' => 'Réorganiser l’élément :key',
        ],
        'toggle_record_content' => [
            'label' => 'Développer/réduire l’élément :key',
        ],
    ],
    'column_manager' => [
        'actions' => [
            'move_down' => [
                'label' => 'Descendre :column',
            ],
            'move_up' => [
                'label' => 'Monter :column',
            ],
            'reorder' => [
                'label' => 'Réorganiser la colonne',
            ],
        ],
        'position' => 'Position :position sur :count.',
        'position_in_group' => 'Position :position sur :count dans :group.',
        'reordered' => ':column déplacée en position :position sur :count.',
        'reordered_deferred' => ':column déplacée en position :position sur :count. Appliquez les colonnes pour enregistrer vos modifications.',
        'reordered_in_group' => ':column déplacée en position :position sur :count dans :group.',
        'reordered_in_group_deferred' => ':column déplacée en position :position sur :count dans :group. Appliquez les colonnes pour enregistrer vos modifications.',
    ],
    'columns' => [
        'editable' => [
            'failure' => 'L’enregistrement n’a pas pu être confirmé. Actualisez la page avant de réessayer.',
            'saved' => 'Modification enregistrée.',
            'saving' => 'Enregistrement de la modification…',
            'unavailable' => 'Cet élément ne peut plus être modifié.',
        ],
        'icon' => [
            'boolean' => [
                'false' => 'Non',
                'true' => 'Oui',
            ],
        ],
        'select' => [
            'loading_message' => 'Chargement…',
            'no_options_message' => 'Aucune option disponible.',
            'no_search_results_message' => 'Aucune option ne correspond à votre recherche.',
            'placeholder' => 'Sélectionnez une option',
            'search_prompt' => 'Commencez à taper pour rechercher',
            'searching_message' => 'Recherche en cours…',
        ],
    ],
    'loading' => 'Chargement…',
    'reordering' => [
        'moved' => ':label déplacé en position :position sur :count.',
        'position' => ':label, position :position sur :count.',
    ],
    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
];
