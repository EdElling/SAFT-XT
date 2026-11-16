<?php

declare(strict_types=1);

// Ergänzt Texte, die in Filaments eigener Übersetzung fehlen, und Texte in Schweizer Rechtschreibung (ss statt ß).
return [
    'builder' => [
        'reordering' => [
            'item' => 'Block',
            'moved' => ':label an Position :position von :count verschoben',
            'position' => ':label, Position :position von :count',
        ],
    ],
    'checkbox_list' => [
        'required_description' => 'Wählen Sie mindestens eine Option aus.',
    ],
    'file_upload' => [
        'editor' => [
            'svg' => [
                'messages' => [
                    'confirmation' => 'Das Bearbeiten von SVG Dateien ist nicht empfohlen, da es Qualitätsverluste beim Verändern der Grösse geben kann.\\n Wirklich fortfahren?',
                    'disabled' => 'Das Bearbeiten von SVG Dateien ist deaktiviert, da es Qualitätsverluste beim Verändern der Grösse geben kann.',
                ],
            ],
        ],
        'placeholder' => 'Keine Dateien hochgeladen',
    ],
    'js_field' => [
        'failed_to_load' => 'Dieses Feld konnte nicht geladen werden. Bitte laden Sie die Seite neu.',
    ],
    'key_value' => [
        'actions' => [
            'move_down' => [
                'label' => 'Nach unten verschieben',
            ],
            'move_up' => [
                'label' => 'Nach oben verschieben',
            ],
        ],
        'reordering' => [
            'moved' => ':label an Position :position von :count verschoben',
            'position' => ':label, Position :position von :count',
            'row' => 'Zeile',
        ],
    ],
    'markdown_editor' => [
        'file_attachments_max_size_message' => 'Hochgeladene Dateien dürfen nicht grösser als :max Kilobytes sein.',
    ],
    'repeater' => [
        'reordering' => [
            'item' => 'Eintrag',
            'moved' => ':label an Position :position von :count verschoben',
            'position' => ':label, Position :position von :count',
        ],
    ],
    'rich_editor' => [
        'actions' => [
            'close_panel' => [
                'label' => 'Panel schliessen',
            ],
            'grid' => [
                'modal' => [
                    'form' => [
                        'from_breakpoint' => [
                            'options' => [
                                '2xl' => 'Zweifach extra gross (2xl)',
                                'lg' => 'Gross (lg)',
                                'xl' => 'Extra gross (xl)',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'file_attachments_max_size_message' => 'Hochgeladene Dateien dürfen nicht grösser als :max Kilobytes sein.',
    ],
    'select' => [
        'error_message' => 'Die Optionen konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
        'required_description' => 'Wählen Sie eine Option aus.',
    ],
    'toggle_buttons' => [
        'required_description' => 'Wählen Sie mindestens eine Option aus.',
    ],
];
