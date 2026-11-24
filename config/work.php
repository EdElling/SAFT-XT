<?php

declare(strict_types=1);

use App\Work\Types\RecomputeWork;

/*
| Arbeiten (Fundament 2, F3a): lange Arbeiten in wiederaufnehmbaren Schritten. Vorangetrieben vom Browser der
| startenden Person und vom Scheduler (Cron: * * * * * php artisan schedule:run, startet jede Minute „work:run“).
| Neue Arten: Klasse mit App\Work\WorkType anlegen und hier eintragen; Plugins über SaftPlugin::workTypes().
*/
return [
    'types' => [
        RecomputeWork::class,
    ],

    // Zeitbudget je Aufruf in Sekunden: Browser (je Abfrage der Seite, unter max_execution_time) und Scheduler.
    'browser_budget' => (int) env('WORK_BROWSER_BUDGET', 15),
    'scheduler_budget' => (int) env('WORK_SCHEDULER_BUDGET', 50),

    // So lange arbeitet eine Aktion (Export, Dokumente, Datenstand …) direkt im Request an der eben gestarteten Arbeit
    // mit, bevor sie auf die Seite der Arbeit weiterleitet; unter max_execution_time.
    'inline_budget' => (int) env('WORK_INLINE_BUDGET', 10),

    // Der Scheduler übernimmt eine Arbeit, an der so viele Sekunden niemand mehr gearbeitet hat.
    'takeover_seconds' => (int) env('WORK_TAKEOVER_SECONDS', 30),

    // Begonnene Schritte ohne Abschluss in Folge (abgebrochener Prozess), danach gilt die Arbeit als fehlgeschlagen.
    'max_stalls' => (int) env('WORK_MAX_STALLS', 3),

    // Das Dashboard (Kachel „Geplante Aufgaben“) meldet offene Arbeiten ohne Fortschritt seit so vielen Minuten.
    'stuck_minutes' => (int) env('WORK_STUCK_MINUTES', 10),

    // Ergebnisdateien so viele Stunden aufbewahren.
    'result_hours' => (int) env('WORK_RESULT_HOURS', 24),
];
