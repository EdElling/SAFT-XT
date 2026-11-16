<?php

declare(strict_types=1);

return [
    'model' => 'Liaison de tables',
    'plural' => 'Liaisons de tables',
    'description' => 'Relient des tables sans clé étrangère – aussi des tables SAFT avec des tables connectées ou des tables de connexions différentes. Comme un champ de liaison : plusieurs lignes de la source pointent vers une ligne de la cible. Utilisables dans les rapports et comme détails dans la vue maître-détail. Rien n’est créé dans la base source.',
    'label' => 'Libellé',
    'label_help' => 'Affiché dans l’arborescence des champs de l’éditeur de rapports et comme titre des détails, p. ex. « Client (ERP) ».',
    'source' => 'Source',
    'target' => 'Cible',
    'table' => 'Table',
    'source_field' => 'Champ source',
    'source_field_help' => 'Champ entier, texte ou sélection contenant la valeur recherchée dans la cible.',
    'target_field' => 'Champ unique de la cible',
    'target_field_help' => 'Table SAFT : id ou un champ « unique » ; table connectée : sa clé. Uniquement les champs compatibles avec le champ source (entier ou texte).',
    'coverage_label' => 'Correspondances',
    'coverage' => ':matched sur :total lignes avec valeur trouvent une cible (:percent %).',
    'coverage_no_snapshot' => 'Aucune indication : une table connectée n’a pas encore d’instantané (ou le champ y manque).',
    'external' => 'connectée, :connection',
    'designer' => 'table SAFT',
    'errors' => [
        'source_field' => 'Le champ n’appartient pas à la table source ou ne peut pas servir de liaison (entier, texte, sélection uniquement).',
        'target_unique' => 'Ce champ de « :table » n’est pas unique. La cible ne peut être que id, un champ « unique » ou la clé d’une table connectée.',
        'kind_mismatch' => 'Les champs source et cible ne correspondent pas : entier uniquement avec entier, texte uniquement avec texte.',
        'same_field' => 'Source et cible sont le même champ.',
        'duplicate' => 'Une liaison de ce champ vers cette table existe déjà.',
    ],
];
