<?php

declare(strict_types=1);

// Ergänzt Texte, die in Filaments eigener Übersetzung fehlen, und Texte in Schweizer Rechtschreibung (ss statt ß).
return [
    'form' => [
        'or_groups' => [
            'block' => [
                'picker_group' => 'Logik',
            ],
        ],
    ],
    'operators' => [
        'number' => [
            'is_max' => [
                'label' => [
                    'inverse' => 'Grösser als',
                ],
                'summary' => [
                    'inverse' => ':attribute ist grösser als :number',
                ],
            ],
        ],
    ],
];
