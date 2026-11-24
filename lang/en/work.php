<?php

declare(strict_types=1);

return [
    'model' => 'Job',
    'plural' => 'Jobs',
    'status' => [
        'queued' => 'queued',
        'running' => 'running',
        'done' => 'done',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
    ],
    'types' => [
        'recompute' => 'Recompute computed fields',
        'export' => 'Export',
    ],
    'export' => [
        'done' => '1 row exported.|:count rows exported.',
        'table_missing' => 'The table no longer exists.',
    ],
    'recompute' => [
        'all_tables' => 'all tables with computed fields',
    ],
    'cancelled' => 'Cancelled.',
    'aborted_repeatedly' => 'Aborted several times without completing (e.g. server time limit exceeded). The job was stopped.',
    'failed_generic' => 'The job failed (reference :reference). Details are in the system log.',
    'plugin_inactive' => 'The plugin “:plugin” is dormant; the job was not run.',
    'started' => 'Job started (:id); the scheduler runs it in the background.',
    'my_work' => 'My jobs',
    'empty' => 'No jobs',
    'system' => 'System',
    'tabs' => [
        'all' => 'All',
        'mine' => 'Mine',
    ],
    'sections' => [
        'details' => 'Details',
    ],
    'fields' => [
        'type' => 'Type',
        'description' => 'Description',
        'user' => 'Started by',
        'app' => 'App',
        'status' => 'Status',
        'progress' => 'Progress',
        'created' => 'Started',
        'finished' => 'Finished',
        'duration' => 'Duration',
        'message' => 'Message',
    ],
    'actions' => [
        'download' => 'Download result',
        'cancel' => 'Cancel',
        'cancel_heading' => 'Cancel job?',
        'cancel_requested' => 'The job is being cancelled.',
        'cancelling' => 'Cancelling …',
    ],
    'progress' => [
        'driving' => 'Runs while this page is open. If you close it, work continues in the background and you will get a notification at the end.',
        'background' => 'Running in the background.',
        'expires' => 'available until :time',
    ],
    'notifications' => [
        'done' => '“:work” is done',
        'failed' => '“:work” failed',
        'open' => 'Open',
    ],
    'widget' => [
        'stuck' => '1 job without progress for over :minutes minutes (is the scheduler running?)|:count jobs without progress for over :minutes minutes (is the scheduler running?)',
        'failed' => '1 job failed in the last 24 hours|:count jobs failed in the last 24 hours',
    ],
];
