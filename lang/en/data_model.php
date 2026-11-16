<?php

declare(strict_types=1);

return [
    'title' => 'Data model: :group',
    'title_all' => 'Data model',
    'show_fields' => 'Show fields',
    'empty' => 'This menu group contains no data tables that you are allowed to see.',
    'load_failed' => 'The diagram library could not be loaded.',
    'render_failed' => 'The diagram could not be drawn.',
    'legend' => [
        'solid' => 'Solid line: set up as detail table',
        'dashed' => 'Dashed line: relation field',
        'link' => '⇄ before the label: table link (without a foreign key, see Administration)',
        'cardinality' => 'Crow’s foot: many records; || exactly one (required field), o| none or one',
        'outside' => 'Dashed border: table outside this group',
        'external' => 'Orange border: linked table (connection in brackets)',
        'fields' => 'PK key, FK relation, UK unique, * required field',
    ],
];
