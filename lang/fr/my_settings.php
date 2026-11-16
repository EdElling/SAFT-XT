<?php

declare(strict_types=1);

return [
    'title' => 'Mes paramètres',
    'appearance' => 'Apparence',
    'high_contrast' => 'Contraste élevé',
    'high_contrast_help' => 'S’applique uniquement à votre compte, sur tous les appareils.',
    'saved' => 'Enregistré',
    'high_contrast_options' => [
        'auto' => 'Automatique',
        'on' => 'Activé',
        'off' => 'Désactivé',
    ],
    'high_contrast_descriptions' => [
        'auto' => 'Contraste élevé si « Augmenter le contraste » est activé dans le système d’exploitation.',
        'on' => 'Texte plus foncé ou plus clair (au moins 7:1), bordures marquées, focus bien visible, liens soulignés.',
        'off' => 'Affichage normal.',
    ],
    'number_format' => 'Format des nombres',
    'number_format_help' => 'S’applique uniquement à votre compte, sur tous les appareils : nombres dans les listes, les détails, les rapports et l’export CSV.',
    'number_format_options' => [
        'auto' => 'Automatique',
        'lang' => 'Selon la langue',
        'CH' => 'Suisse',
    ],
    'number_format_descriptions' => [
        'auto' => 'Format suisse si votre navigateur est réglé sur la Suisse (p. ex. français (Suisse)), sinon selon la langue.',
        'lang' => '1.234,50 (Deutsch, Italiano) · 1,234.50 (English) · 1 234,50 (Français)',
        'CH' => '1\'234.50 – en français selon la règle suisse 1 234,50 ; CSV avec point décimal.',
    ],
    'two_factor' => 'Authentification à deux facteurs',
    'two_factor_help' => 'Protège votre compte même si quelqu’un connaît votre mot de passe : à la connexion, vous saisissez en plus un code d’une application d’authentification. Conservez les codes de récupération en lieu sûr – ils vous permettent d’entrer sans téléphone.',
    'two_factor_required' => 'L’authentification à deux facteurs est obligatoire pour votre compte. Conservez les codes de récupération en lieu sûr – ils vous permettent d’entrer sans téléphone.',
];
