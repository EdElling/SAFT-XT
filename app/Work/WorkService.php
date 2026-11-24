<?php

declare(strict_types=1);

namespace App\Work;

use App\Auth\RoleSimulation;
use App\Jobs\AdvanceWork;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Arbeiten starten und abbrechen. Vorangetrieben werden sie vom WorkRunner – drei Arten zu starten:
 * start() legt nur an (Browser oder Scheduler treiben; mit WORK_DRIVER=queue ein Queue-Worker über App\Jobs\AdvanceWork),
 * startAndAdvance() arbeitet kurz im laufenden Request mit (das Ergebnis kleiner Arbeiten kommt ohne Umweg),
 * startAndDrive() treibt bis zum Ende (Konsole, Aufgaben – ohne Job, der Aufrufer wartet ja selbst).
 */
final readonly class WorkService
{
    public function __construct(
        private WorkRegistry $registry,
        private RoleSimulation $simulation,
        private WorkRunner $runner,
        private TimeLimit $timeLimit,
    ) {}

    /**
     * Legt eine Arbeit an (Status „wartet“); vorbereitet wird sie im ersten Schritt. Startet die angemeldete Person
     * unter „Ansicht als Rolle“, gelten die simulierten Rollen auch im Hintergrund. $files (Name im Ordner der Arbeit
     * => Pfad) werden vorher in den Ordner kopiert, z. B. eine hochgeladene Datei, die die Schritte lesen.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $files
     */
    public function start(string $type, array $parameters = [], ?User $user = null, ?int $appId = null, array $files = []): WorkItem
    {
        $item = $this->create($type, $parameters, $user, $appId, $files);

        if (self::queueDriver()) {
            AdvanceWork::dispatch($item->id);
        }

        return $item;
    }

    /** Treibt ein Queue-Worker die Arbeiten (work.driver = queue, Queue-Verbindung nicht „sync“)? */
    public static function queueDriver(): bool
    {
        $connection = (string) config('queue.default', 'sync');

        return config('work.driver') === 'queue' && config("queue.connections.{$connection}.driver") !== 'sync';
    }

    /**
     * Anlegen ohne Treiber.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $files
     */
    private function create(string $type, array $parameters, ?User $user, ?int $appId, array $files): WorkItem
    {
        $this->registry->get($type);
        $simulated = $user !== null && Auth::id() === $user->id && $this->simulation->isActive()
            ? $this->simulation->active()?->modelKeys()
            : null;

        $item = WorkItem::query()->create([
            'type' => $type,
            'parameters' => $parameters,
            'user_id' => $user?->id,
            'app_id' => $appId,
            'simulated_roles' => $simulated,
            'status' => WorkItem::QUEUED,
        ]);

        foreach ($files as $name => $path) {
            $stream = fopen($path, 'r');

            if ($stream === false || ! Storage::disk('local')->put($item->directory().'/'.basename((string) $name), $stream)) {
                throw new RuntimeException('Datei der Arbeit nicht kopiert: '.$name);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $item;
    }

    /**
     * Starten und sofort im laufenden Request mitarbeiten (Fundament 2, F3b): bis zu $budget Sekunden (Vorgabe
     * work.inline_budget, immer unter dem Zeitlimit von PHP). Ist die Arbeit danach fertig, hat die Person ihr Ergebnis
     * ohne Umweg; sonst leitet der Aufrufer auf die Seite der Arbeit weiter, wo ihr Browser weiterarbeitet und der
     * Scheduler übernimmt, sobald sie die Seite verlässt.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $files
     */
    public function startAndAdvance(string $type, array $parameters = [], ?User $user = null, ?int $appId = null, ?float $budget = null, array $files = []): WorkItem
    {
        return $this->advance($this->start($type, $parameters, $user, $appId, $files), $budget);
    }

    /** Im laufenden Request an einer Arbeit mitarbeiten, bis zu $budget Sekunden (Vorgabe work.inline_budget). */
    public function advance(WorkItem $item, ?float $budget = null): WorkItem
    {
        return $this->runner->advance($item, $this->timeLimit->budget($budget ?? (float) config('work.inline_budget', 10)), interactive: true);
    }

    /**
     * Starten und bis zum Ende treiben – für Konsole und Aufgaben, die auf das Ergebnis warten.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $files
     */
    public function startAndDrive(string $type, array $parameters = [], ?User $user = null, ?int $appId = null, array $files = []): WorkItem
    {
        return $this->drive($this->create($type, $parameters, $user, $appId, $files));
    }

    /** Eine Arbeit bis zum Ende treiben; hat ein anderer Treiber die Sperre, warten statt drängeln. */
    public function drive(WorkItem $item, float $slice = 60): WorkItem
    {
        while ($item->isOpen()) {
            $started = microtime(true);
            $item = $this->runner->advance($item, $slice);

            if ($item->isOpen() && microtime(true) - $started < 1) {
                usleep(500_000);
            }
        }

        return $item;
    }

    /**
     * Abbrechen: Arbeitet gerade kein Treiber daran, endet die Arbeit sofort; sonst bekommt sie eine Marke, und der
     * Treiber beendet sie zwischen zwei Schritten.
     */
    public function cancel(WorkItem $item): WorkItem
    {
        if (! $item->isOpen()) {
            return $item;
        }

        $lock = Cache::lock(WorkRunner::lockKey($item), 10);

        if (! $lock->get()) {
            $item->forceFill(['cancel_requested_at' => $item->cancel_requested_at ?? now()])->save();

            return $item;
        }

        try {
            $item->refresh();

            if ($item->isOpen()) {
                $item->forceFill(['cancel_requested_at' => $item->cancel_requested_at ?? now()]);
                $this->runner->finish($item, WorkItem::CANCELLED, __('work.cancelled'));
            }

            return $item;
        } finally {
            $lock->release();
        }
    }
}
