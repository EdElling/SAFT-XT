<?php

declare(strict_types=1);

return [
    'title' => 'Modèle de données : :group',
    'title_all' => 'Modèle de données',
    'show_fields' => 'Afficher les champs',
    'empty' => 'Ce groupe de menu ne contient aucune table de données que vous êtes autorisé à voir.',
    'load_failed' => 'La bibliothèque de diagrammes n’a pas pu être chargée.',
    'render_failed' => 'Le diagramme n’a pas pu être dessiné.',
    'legend' => [
        'solid' => 'Ligne continue : configurée comme table de détail',
        'dashed' => 'Ligne pointillée : champ de liaison',
        'link' => '⇄ devant le libellé : liaison de tables (sans clé étrangère, voir Administration)',
        'cardinality' => 'Patte d’oie : plusieurs enregistrements ; || exactement un (champ obligatoire), o| aucun ou un',
        'outside' => 'Cadre pointillé : table hors de ce groupe',
        'external' => 'Cadre orange : table liée (connexion entre parenthèses)',
        'fields' => 'PK clé, FK liaison, UK unique, * champ obligatoire',
    ],
];
