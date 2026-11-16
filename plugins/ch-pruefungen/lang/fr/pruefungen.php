<?php

declare(strict_types=1);

return [
    'plugin' => 'Contrôles suisses',
    'description' => 'Plugin d’exemple : règles de validation pour numéro AVS, IBAN, IDE et numéro postal ; fonction de formule rappen() pour l’arrondi à 5 centimes.',
    'rules' => [
        'ahv' => 'Numéro AVS',
        'iban' => 'IBAN',
        'uid' => 'IDE (numéro CHE)',
        'plz' => 'Numéro postal suisse',
    ],
    'invalid' => [
        'ahv' => 'Numéro AVS non valide (756.xxxx.xxxx.xx, chiffre de contrôle).',
        'iban' => 'IBAN non valide (somme de contrôle ou longueur).',
        'uid' => 'IDE non valide (CHE-xxx.xxx.xxx, chiffre de contrôle).',
        'plz' => 'Numéro postal suisse non valide (quatre chiffres).',
    ],
    'functions' => [
        'rappen' => 'Arrondit à 5 centimes, p. ex. rappen(montant * 1.081)',
    ],
];
