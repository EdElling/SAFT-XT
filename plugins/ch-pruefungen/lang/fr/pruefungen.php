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
    'descriptions' => [
        'ahv' => '13 chiffres commençant par 756, avec chiffre de contrôle (EAN-13) ; points et espaces autorisés, p. ex. 756.1234.5678.97.',
        'iban' => 'Code pays, deux chiffres de contrôle et numéro de compte ; somme de contrôle selon ISO 13616, exactement 21 caractères pour CH et LI ; espaces autorisés.',
        'uid' => 'CHE, puis neuf chiffres avec chiffre de contrôle, p. ex. CHE-123.456.789 ; le tiret et les points peuvent manquer.',
        'plz' => 'Quatre chiffres de 1000 à 9999.',
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
