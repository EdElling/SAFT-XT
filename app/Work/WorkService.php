<?php

declare(strict_types=1);

namespace App\Work;

use App\Auth\RoleSimulation;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Arbeiten starten und abbrechen. Vorangetrieben werden sie vom WorkRunner – drei Arten zu starten:
 * start() legt nur an (Browser oder Scheduler treiben), startAndAdvance() arbeitet kurz im laufenden Request mit
 * (das Ergebnis kleiner Arbeiten kommt ohne Umweg), startAndDrive() treibt bis zum Ende (Konsole, Aufgaben).
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
     * unter „Ansicht als Rolle“, gelten die simulierten Rollen auch im Hintergrund.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function start(string $type, array $parameters = [], ?User $user = null, ?int $appId = null): WorkItem
    {
        $this->registry->get($type);
        $simulated = $user !== null && Auth::id() === $user->id && $this->simulation->isActive()
            ? $this->simulation->active()?->modelKeys()
            : null;

        return WorkItem::query()->create([
            'type' => $type,
            'parameters' => $parameters,
            'user_id' => $user?->id,
            'app_id' => $appId,
            'simulated_roles' => $simulated,
            'status' => WorkItem::QUEUED,
        ]);
    }

    /**
     * Starten und sofort im laufenden Request mitarbeiten (Fundament 2, F3b): bis zu $budget Sekunden (Vorgabe
     * work.inline_budget, immer unter dem Zeitlimit von PHP). Ist die Arbeit danach fertig, hat die Person ihr Ergebnis
     * ohne Umweg; sonst leitet der Aufrufer auf die Seite der Arbeit weiter, wo ihr Browser weiterarbeitet und der
     * Scheduler übernimmt, sobald sie die Seite verlässt.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function startAndAdvance(string $type, array $parameters = [], ?User $user = null, ?int $appId = null, ?float $budget = null): WorkItem
    {
        $item = $this->start($type, $parameters, $user, $appId);

        return $this->runner->advance($item, $this->timeLimit->budget($budget ?? (float) config('work.inline_budget', 10)), interactive: true);
    }

    /**
     * Starten und bis zum Ende treiben – für Konsole und Aufgaben, die auf das Ergebnis warten.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function startAndDrive(string $type, array $parameters = [], ?User $user = null, ?int $appId = null): WorkItem
    {
        return $this->drive($this->start($type, $parameters, $user, $appId));
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
