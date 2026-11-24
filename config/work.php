<?php

declare(strict_types=1);

use App\Work\Types\AppDesignerCreateWork;
use App\Work\Types\AppDesignerReportsWork;
use App\Work\Types\AppDesignerTurnWork;
use App\Work\Types\DocumentsWork;
use App\Work\Types\ExportWork;
use App\Work\Types\RecomputeWork;
use App\Work\Types\RecordImportWork;
use App\Work\Types\SnapshotRefreshWork;

/*
| Arbeiten (Fundament 2, F3a): lange Arbeiten in wiederaufnehmbaren Schritten. Vorangetrieben vom Browser der
| startenden Person und vom Scheduler (Cron: * * * * * php artisan schedule:run, startet jede Minute „work:run“) –
| oder mit WORK_DRIVER=queue von einem Queue-Worker (F3b); der Scheduler bleibt dann Rückfallebene.
| Neue Arten: Klasse mit App\Work\WorkType anlegen und hier eintragen; Plugins über SaftPlugin::workTypes().
*/
return [
    'types' => [
        RecomputeWork::class,
        ExportWork::class,
        DocumentsWork::class,
        RecordImportWork::class,
        SnapshotRefreshWork::class,
        AppDesignerTurnWork::class,
        AppDesignerCreateWork::class,
        AppDesignerReportsWork::class,
    ],

    // Treiber: auto = Browser der startenden Person und Scheduler; queue = ein Queue-Worker (php artisan queue:work)
    // treibt jede Arbeit gleich nach dem Start voran (App\Jobs\AdvanceWork), der Browser zeigt nur noch an. Auf der
    // Queue-Verbindung „sync“ nie – sonst liefe die ganze Arbeit im Request.
    'driver' => env('WORK_DRIVER', 'auto'),

    // Zeitbudget je Aufruf in Sekunden: Browser (je Abfrage der Seite, unter max_execution_time), Scheduler und Queue-Job
    // (der sich danach wieder einreiht, solange die Arbeit offen ist).
    'browser_budget' => (int) env('WORK_BROWSER_BUDGET', 15),
    'scheduler_budget' => (int) env('WORK_SCHEDULER_BUDGET', 50),
    'queue_budget' => (int) env('WORK_QUEUE_BUDGET', 50),

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
