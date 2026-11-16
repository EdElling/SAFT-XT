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
];
