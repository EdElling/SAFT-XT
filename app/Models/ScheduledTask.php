<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\ScheduledTask as BaseScheduledTask;
use App\Models\Concerns\HasAuditLog;
use App\Tasks\TaskRegistry;
use App\Tasks\TaskSchedule;
use App\Tasks\TaskType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * Geplante Aufgabe: Art (TaskType) + Parameter + Zeitplan. Der nächste Termin wird beim Speichern
 * neu berechnet, sobald sich Zeitplan oder Aktivierung ändern.
 */
class ScheduledTask extends BaseScheduledTask
{
    use HasAuditLog;

    public const string SUCCESS = 'success';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    /** @var array<string, mixed> Wie die Vorgaben der Datenbank, damit ein neues Model sie sofort kennt. */
    protected $attributes = ['at_time' => '00:00', 'is_active' => true, 'is_system' => false, 'consecutive_failures' => 0];

    /** @var list<string> Laufzeitwerte: ändern sich bei jedem Lauf, gehören nicht ins Änderungsprotokoll. */
    protected array $auditExcept = ['next_run_at', 'last_run_at', 'last_status', 'consecutive_failures'];

    protected static function booted(): void
    {
        static::saving(function (self $task): void {
            if ($task->isDirty(['frequency', 'interval_hours', 'at_time', 'weekday', 'is_active']) || ($task->is_active && $task->next_run_at === null)) {
                $task->next_run_at = $task->is_active ? $task->schedule()->next(now()) : null;
            }
        });
    }

    /** @return HasMany<TaskRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function type(): TaskType
    {
        return app(TaskRegistry::class)->get($this->type);
    }

    public function schedule(): TaskSchedule
    {
        return TaskSchedule::for($this);
    }

    /** @return array<string, mixed> */
    public function parameterValues(): array
    {
        return (array) ($this->parameters ?? []);
    }

    /** Eigener Name oder die Bezeichnung der Art. */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : $this->typeLabel();
    }

    public function typeLabel(): string
    {
        return app(TaskRegistry::class)->has($this->type) ? app(TaskRegistry::class)->labelOf($this->type()) : $this->type;
    }

    /** Was die Aufgabe betrifft, z. B. die Tabelle; leer, wenn die Art unbekannt ist. */
    public function description(): string
    {
        try {
            return $this->type()->describe($this->parameterValues());
        } catch (Throwable) {
            return '';
        }
    }
}
