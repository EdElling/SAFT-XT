<?php

declare(strict_types=1);

return [
    'title' => 'Data snapshots',
    'description' => 'Immutable copies of this table for reports. Reports always use the newest complete snapshot. Deletions in the source take effect only with the next snapshot; retention removes older snapshots.',
    'refresh' => 'Refresh now',
    'refreshed' => 'Snapshot taken: :rows rows',
    'started' => 'The snapshot is being taken in the background; the list shows it once it is complete.',
    'retention' => 'Retention',
    'keep' => 'Keep last snapshots',
    'keep_help' => 'At least 1; the newest snapshot is always kept.',
    'max_days' => 'At most days',
    'max_days_help' => 'Empty = no limit.',
    'retention_saved' => 'Retention saved',
    'delete' => 'Delete',
    'delete_all' => 'Delete all snapshots',
    'delete_all_description' => 'All snapshots of this table are deleted. Reports on it only run again after the next refresh.',
    'deleted' => 'Snapshot deleted',
    'deleted_all' => 'All snapshots deleted',
    'time' => 'Time',
    'rows' => 'Rows',
    'status' => 'Status',
    'by' => 'Triggered by',
    'automatic' => 'automatic',
    'message' => 'Message',
    'statuses' => [
        'running' => 'running',
        'complete' => 'complete',
        'failed' => 'failed',
    ],
    'none' => 'No snapshot yet',
    'as_of' => 'Data as of: :table (:connection) at :time',
    'errors' => [
        'failed' => 'The snapshot could not be taken; the previous one remains valid. Details in the application log.',
        'too_large' => 'The table has more than :max rows – too large for a snapshot.',
        'not_external' => 'Snapshots exist only for connected tables.',
        'busy' => 'This table is being refreshed right now. Please try again later.',
        'aborted' => 'Aborted (the process was interrupted).',
    ],
];
