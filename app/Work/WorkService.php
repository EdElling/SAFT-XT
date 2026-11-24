<?php

declare(strict_types=1);

namespace App\Work;

use App\Auth\RoleSimulation;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/** Arbeiten starten und abbrechen. Vorangetrieben werden sie vom WorkRunner. */
final readonly class WorkService
{
    public function __construct(private WorkRegistry $registry, private RoleSimulation $simulation, private WorkRunner $runner) {}

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
