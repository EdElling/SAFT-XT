<?php

declare(strict_types=1);

use App\Tasks\Types\SnapshotConnectionRefreshTask;
use App\Tasks\Types\SnapshotPruneTask;
use App\Tasks\Types\SnapshotRefreshTask;
use App\Tasks\Types\TaskRunsPruneTask;

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
    ],

    // Ein Lauf, der länger als so viele Stunden auf „läuft“ steht, gilt als abgebrochen.
    'hanging_hours' => (int) env('TASKS_HANGING_HOURS', 6),

    // Läuft der Scheduler länger nicht, warnt die Verwaltung (Minuten).
    'heartbeat_minutes' => (int) env('TASKS_HEARTBEAT_MINUTES', 15),

    // Datenstände, die so lange auf „läuft“ stehen, entfernt die Aufgabe „Datenstände bereinigen“.
    'snapshot_hanging_hours' => (int) env('TASKS_SNAPSHOT_HANGING_HOURS', 2),
];
