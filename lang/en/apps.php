<?php

declare(strict_types=1);

return [
    'model' => 'App',
    'plural' => 'Apps',
    'app' => 'App',
    'name' => 'Name',
    'description' => 'Description',
    'prefix' => 'Table prefix',
    'prefix_help' => 'Suggested when creating tables, e.g. “lager” → “lager_artikel”. Lower-case letters and digits.',
    'icon' => 'Icon',
    'description_page' => 'Business applications group tables, menus, reports, roles and tasks that belong together. Only empty apps can be deleted.',
    'delete_only_empty' => 'Only empty apps can be deleted.',
    'system' => 'System',
    'app_help' => 'Belongs to this business application; relations are only possible within one app.',
    'parts' => [
        'tables' => 'Tables',
        'menus' => 'Menus',
        'reports' => 'Reports',
        'report_links' => 'Links',
        'roles' => 'Roles',
        'tasks' => 'Tasks',
    ],
    'not_empty' => 'The app “:name” is not empty. First move or delete its tables, menus, reports, roles and tasks.',
    'errors' => [
        'unknown' => 'This app does not exist.',
        'required' => 'Please choose an app.',
        'cross_app_relation' => 'Relations are only possible to tables of the same app – “:table” belongs to the app “:app”.',
        'cross_app_link' => 'Table links are only possible between tables of the same app.',
    ],
];
