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
    ],
    'recompute' => [
        'all_tables' => 'all tables with computed fields',
    ],
    'cancelled' => 'Cancelled.',
    'aborted_repeatedly' => 'Aborted several times without completing (e.g. server time limit exceeded). The job was stopped.',
    'failed_generic' => 'The job failed (reference :reference). Details are in the system log.',
    'plugin_inactive' => 'The plugin “:plugin” is dormant; the job was not run.',
    'started' => 'Job started (:id); the scheduler runs it in the background.',
];
