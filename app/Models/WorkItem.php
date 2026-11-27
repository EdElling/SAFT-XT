<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\WorkItem as BaseWorkItem;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Work\ReportsPhaseProgress;
use App\Work\WorkRegistry;
use App\Work\WorkType;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eine Arbeit (Fundament 2, F3a): ein Lauf einer Art (WorkType) mit Parametern, Fortschritt und Cursor. Ins
 * Änderungsprotokoll kommen nur der Start und die Wechsel des Status (Ende, Abbruch, Fehler), nicht der Fortschritt.
 */
class WorkItem extends BaseWorkItem
{
    use HasAuditLog;
    use HasStableKey;

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    /** @var list<string> */
    public const array OPEN = [self::QUEUED, self::RUNNING];

    /** @var array<string, mixed> Wie die Vorgaben der Datenbank, damit ein neues Model sie sofort kennt. */
    protected $attributes = ['status' => self::QUEUED, 'done' => 0, 'stalls' => 0];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('work_item')
            ->logOnly(['type', 'status', 'message'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** Adressen nennen die Arbeit über ihren stabilen Schlüssel, nicht über die fortlaufende ID. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function type(): WorkType
    {
        return app(WorkRegistry::class)->get($this->type);
    }

    /** Bezeichnung der Art (mit Herkunft bei Plugins); die Kennung, wenn die Art nicht mehr bekannt ist. */
    public function typeLabel(): string
    {
        $registry = app(WorkRegistry::class);

        return $registry->has($this->type) ? $registry->labelOf($this->type()) : $this->type;
    }

    public function description(): string
    {
        return app(WorkRegistry::class)->has($this->type) ? $this->type()->describe($this->parameterValues()) : '';
    }

    /** Ergebnisdatei, solange sie aufbewahrt wird: Name im Ordner der Arbeit, sonst null. */
    public function resultFile(): ?string
    {
        $file = $this->result['file'] ?? null;

        return $this->status === self::DONE && is_string($file) && $file !== '' && ($this->expires_at === null || $this->expires_at->isFuture())
            ? basename($file)
            : null;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** @return array<string, mixed> */
    public function parameterValues(): array
    {
        return (array) ($this->parameters ?? []);
    }

    /** Fortschritt in Prozent; null, solange die Menge nicht bekannt ist. */
    /**
     * Fortschritt in Prozent; bei Arten mit Phasen (ReportsPhaseProgress, B-176) der laufenden Phase.
     */
    public function percent(): ?int
    {
        if ($this->status === self::DONE) {
            return 100;
        }

        [$done, $total] = $this->phaseCounts() ?? [$this->done, $this->total];

        return $total === null || $total === 0 ? null : (int) max(0, min(100, floor($done * 100 / $total)));
    }

    /** @return array{0: int, 1: int}|null erledigt und Menge der laufenden Phase, wenn die Art sie meldet */
    private function phaseCounts(): ?array
    {
        $registry = app(WorkRegistry::class);

        if (! $this->isOpen() || ! $registry->has($this->type)) {
            return null;
        }

        $type = $registry->get($this->type);

        return $type instanceof ReportsPhaseProgress ? $type->phaseProgress((array) ($this->state ?? []), (int) $this->done, $this->total) : null;
    }

    /** Ordner für Ergebnisdateien auf der Platte „local“ (storage/app/private). */
    public function directory(): string
    {
        return 'work/'.$this->uuid;
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }
}
