<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Der Laravel-Scheduler braucht den Cron-Eintrag
// * * * * * cd /pfad/zu/saft && php artisan schedule:run >> /dev/null 2>&1

// Arbeiten (Fundament 2, F3a) im Hintergrund vorantreiben; Zeitbudget work.scheduler_budget (unter einer Minute).
Schedule::command('work:run')->everyMinute()->withoutOverlapping(10);

// Geplante Aufgaben (Administration › Geplante Aufgaben).
Schedule::command('tasks:run-due')->everyMinute()->withoutOverlapping(6 * 60);
