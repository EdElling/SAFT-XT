<?php

declare(strict_types=1);

/** KI-App-Designer (B-107, Etappe 7a). */
return [
    'untitled' => 'New draft',
    'errors' => [
        'app_name' => 'The app needs a name.',
        'prefix' => 'The prefix ":prefix" is invalid: 1–20 lower case letters and digits, starting with a letter.',
        'prefix_taken' => 'The prefix ":prefix" is already used by another app.',
        'no_tables' => 'The draft has no tables.',
        'table_prefix' => 'The table ":table" must start with the prefix ":prefix".',
        'duplicate_table' => 'The table ":table" appears twice.',
        'menu_label' => 'A menu group has no label.',
        'menu_table' => 'The menu group names the table ":table", which is not in the draft.',
        'menu_twice' => 'The table ":table" is in more than one menu group.',
        'invalid' => 'No valid draft: :errors',
        'nothing_to_create' => 'There is no draft yet from which an app can be created.',
        'already_created' => 'An app has already been created from this draft.',
    ],
];
