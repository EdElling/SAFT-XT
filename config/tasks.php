<?php

declare(strict_types=1);

use App\Tasks\Types\ActivityPruneTask;
use App\Tasks\Types\BackupPruneTask;
use App\Tasks\Types\DatabaseBackupTask;
use App\Tasks\Types\DemoResetTask;
use App\Tasks\Types\FilesPruneTask;
use App\Tasks\Types\SessionsPruneTask;
use App\Tasks\Types\SnapshotConnectionRefreshTask;
use App\Tasks\Types\SnapshotPruneTask;
use App\Tasks\Types\SnapshotRefreshTask;
use App\Tasks\Types\TaskRunsPruneTask;
use App\Tasks\Types\WorkPruneTask;

/*
| Geplante Aufgaben (Administration › Geplante Aufgaben). Ausgeführt vom Laravel-Scheduler
| (Cron: * * * * * php artisan schedule:run), der jede Minute „tasks:run-due“ startet.
| Neue Arten von Aufgaben: Klasse mit App\Tasks\TaskType anlegen und hier eintragen.
*/
return [
    'types' => [
        SnapshotRefreshTask::class,
        SnapshotConnectionRefreshTask::class,
        SnapshotPruneTask::class,
        TaskRunsPruneTask::class,
        ActivityPruneTask::class,
        FilesPruneTask::class,
        DatabaseBackupTask::class,
        BackupPruneTask::class,
        DemoResetTask::class,
        WorkPruneTask::class,
        SessionsPruneTask::class,
    ],

    // Ein Lauf, der länger als so viele Stunden auf „läuft“ steht, gilt als abgebrochen.
    'hanging_hours' => (int) env('TASKS_HANGING_HOURS', 6),

    // Läuft der Scheduler länger nicht, warnt die Verwaltung (Minuten).
    'heartbeat_minutes' => (int) env('TASKS_HEARTBEAT_MINUTES', 15),

];
