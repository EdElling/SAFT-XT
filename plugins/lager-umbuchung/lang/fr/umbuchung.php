<?php

declare(strict_types=1);

return [
    'plugin' => 'Transfert de stock',
    'description' => 'Plugin d’exemple : transférer des marchandises entre emplacements ; les mouvements mettent à jour le stock ; alerte sous le stock minimum (démo entrepôt).',
    'permission' => 'Effectuer des transferts',
    'title' => 'Transfert',
    'help' => 'Transfère des marchandises d’un emplacement à un autre. Les stocks des deux emplacements sont mis à jour et deux mouvements de type « Transfert » (−/+) sont créés.',
    'fields' => [
        'article' => 'Article',
        'from' => 'Depuis l’emplacement',
        'to' => 'Vers l’emplacement',
        'quantity' => 'Quantité',
        'note' => 'Remarque',
    ],
    'submit' => 'Transférer',
    'available' => 'Disponible à la source : :count',
    'location_with_stock' => ':location (stock : :count)',
    'no_stock_here' => 'Aucun stock à cet emplacement',
    'no_stock' => 'Cet article n’a de stock à aucun emplacement.',
    'unavailable_title' => 'Démo entrepôt incomplète',
    'unavailable' => 'Cette page nécessite des tables et champs de la démo entrepôt. Il manque :',
    'done' => ':count × :article transféré(s) de :from vers :to.',
    'minimum' => [
        'title' => 'Stock minimum non atteint',
        'body' => 'Stock total de « :article » : :count (stock minimum :minimum).',
    ],
    'errors' => [
        'same_location' => 'Les emplacements source et cible doivent être différents.',
        'quantity' => 'La quantité doit être d’au moins 1.',
        'not_enough' => 'Stock insuffisant à la source (disponible : :available).',
        'invalid' => 'Le transfert enfreint des règles des tables :',
        'negative' => 'Stock insuffisant : « :article » à l’emplacement :location en a :available ; le mouvement le rendrait négatif.',
        'stock_locked' => 'Les stocks sont tenus par les mouvements de stock. Veuillez saisir les corrections comme mouvement de type « Inventaire ».',
        'rights' => 'Il vous manque des droits sur les données pour : :tables',
    ],
    'widget' => [
        'label' => 'Entrepôt : stock minimum',
        'below' => 'Articles sous le stock minimum',
        'none' => 'Tous les articles au-dessus du stock minimum',
        'item' => ':article (:count/:minimum)',
        'more' => ':names et :count autres',
        'total' => 'Stock total (pièces)',
        'transfer' => 'Vers le transfert',
    ],
];
