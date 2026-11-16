<?php

declare(strict_types=1);

return [
    'plugin' => 'Tableau des stocks',
    'description' => 'Plugin d’exemple pour les guides du manuel du développeur : matrice des stocks, tableau des stocks par glisser-déposer, tuile, écouteurs et règle de validation pour les articles (démo entrepôt, nécessite Transfert de stock).',
    'permissions' => [
        'view' => 'Afficher la matrice des stocks',
        'transfer' => 'Transférer sur le tableau des stocks',
    ],
    'unavailable_title' => 'Démo entrepôt incomplète',
    'unavailable' => 'Cette page nécessite des tables et des champs de la démo entrepôt. Il manque :',
    'no_rights' => 'Vous n’avez pas le droit d’afficher les articles, les emplacements et les stocks.',
    'actions' => [
        'matrix' => 'Matrice des stocks',
        'board' => 'Répartir sur le tableau des stocks',
    ],
    'rules' => [
        'article_number' => 'Numéro d’article (démo entrepôt)',
        'article_number_invalid' => 'Numéro d’article attendu au format A-1234 (une lettre, un tiret, quatre chiffres).',
    ],
    'errors' => [
        'number_locked' => 'Le numéro d’article :number est utilisé dans des mouvements de stock et ne peut plus être modifié.',
    ],
    'matrix' => [
        'title' => 'Matrice des stocks',
        'number' => 'Numéro',
        'article' => 'Article',
        'total' => 'Total',
        'minimum' => 'Stock minimum',
        'below_minimum' => 'Seulement sous le stock minimum',
        'empty' => 'Aucun article',
    ],
    'board' => [
        'title' => 'Tableau des stocks',
        'article' => 'Article',
        'help' => 'Faites glisser le stock vers un autre emplacement pour le transférer.',
        'drag' => 'Faire glisser vers un autre emplacement',
        'empty' => 'Aucun stock',
        'modal' => 'Transférer de :from vers :to',
        'submit' => 'Transférer',
        'quantity' => 'Quantité',
        'rights' => 'Il vous manque des droits sur les données pour : :tables',
        'invalid' => 'Le transfert viole des règles des tables :',
        'done' => ':count transférés de :from vers :to.',
    ],
    'widget' => [
        'label' => 'Entrepôt : stock par emplacement',
        'description' => 'Somme de tous les articles',
        'quantity' => 'Stock',
    ],
];
