<?php

declare(strict_types=1);

// Ergänzt Texte, die in Filaments eigener Übersetzung fehlen.
return [
    'actions' => [
        'move_record_down' => [
            'label' => ':label nach unten verschieben',
        ],
        'move_record_up' => [
            'label' => ':label nach oben verschieben',
        ],
    ],
    'column_manager' => [
        'actions' => [
            'move_down' => [
                'label' => ':column nach unten verschieben',
            ],
            'move_up' => [
                'label' => ':column nach oben verschieben',
            ],
        ],
        'position' => 'Position :position von :count.',
        'position_in_group' => 'Position :position von :count in :group.',
        'reordered' => ':column an Position :position von :count verschoben.',
        'reordered_deferred' => ':column an Position :position von :count verschoben. Übernehmen Sie die Spalten, um die Änderung zu speichern.',
        'reordered_in_group' => ':column an Position :position von :count in :group verschoben.',
        'reordered_in_group_deferred' => ':column an Position :position von :count in :group verschoben. Übernehmen Sie die Spalten, um die Änderung zu speichern.',
    ],
    'columns' => [
        'editable' => [
            'failure' => 'Das Speichern konnte nicht bestätigt werden. Laden Sie die Seite neu, bevor Sie es erneut versuchen.',
            'saved' => 'Änderung gespeichert.',
            'saving' => 'Änderung wird gespeichert …',
            'unavailable' => 'Dieser Eintrag kann nicht mehr bearbeitet werden.',
        ],
    ],
    'reordering' => [
        'moved' => ':label an Position :position von :count verschoben.',
        'position' => ':label, Position :position von :count.',
    ],
];
